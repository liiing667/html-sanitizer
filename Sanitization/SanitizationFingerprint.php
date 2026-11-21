<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Sanitization;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Builds deterministic hashes for policy snapshots and complete reports.
 *
 * The policy fingerprint deliberately describes the serializable policy, not
 * a process-local object id or a random nonce. Two identical sanitizers in
 * different processes therefore produce the same generation.
 *
 * @internal
 */
final class SanitizationFingerprint
{
    public static function policy(HtmlSanitizerConfig $config, string $context, string $parserContext): string
    {
        $sanitizers = [];

        foreach ($config->getAttributeSanitizers() as $sanitizer) {
            $description = ['class' => $sanitizer::class];

            try {
                $elements = $sanitizer->getSupportedElements();
                $attributes = $sanitizer->getSupportedAttributes();
                $description['elements'] = null === $elements ? null : self::sortedStrings($elements);
                $description['attributes'] = null === $attributes ? null : self::sortedStrings($attributes);
            } catch (\Throwable) {
                // A sanitizer that cannot expose its metadata is still part of
                // the policy through its class name.
            }

            $sanitizers[] = $description;
        }

        return self::hash([
            'context' => $context,
            'parser_context' => $parserContext,
            'default_action' => $config->getDefaultAction()->value,
            'allowed_elements' => $config->getAllowedElements(),
            'blocked_elements' => $config->getBlockedElements(),
            'dropped_elements' => $config->getDroppedElements(),
            'forced_attributes' => $config->getForcedAttributes(),
            'allowed_link_schemes' => self::sortedStrings($config->getAllowedLinkSchemes()),
            'allowed_link_hosts' => null === $config->getAllowedLinkHosts() ? null : self::sortedStrings($config->getAllowedLinkHosts()),
            'allow_relative_links' => $config->getAllowRelativeLinks(),
            'allowed_media_schemes' => self::sortedStrings($config->getAllowedMediaSchemes()),
            'allowed_media_hosts' => null === $config->getAllowedMediaHosts() ? null : self::sortedStrings($config->getAllowedMediaHosts()),
            'allow_relative_medias' => $config->getAllowRelativeMedias(),
            'force_https_urls' => $config->getForceHttpsUrls(),
            'max_input_length' => $config->getMaxInputLength(),
            'attribute_sanitizers' => $sanitizers,
        ]);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sortedStrings(array $values): array
    {
        sort($values, \SORT_STRING);

        return $values;
    }

    /**
     * Normalizes arrays and plain value objects while avoiding process-local
     * identities. Attribute sanitizer instances intentionally contribute their
     * class and supported names only: their runtime counters are not policy.
     */
    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if (\is_array($value)) {
            if ($depth >= 12) {
                return '[depth-limit]';
            }

            if (array_is_list($value)) {
                return array_map(static fn (mixed $item): mixed => self::normalize($item, $depth + 1), $value);
            }

            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item, $depth + 1);
            }

            return $value;
        }

        if ($value instanceof \UnitEnum) {
            return ['enum' => $value::class, 'case' => $value->name];
        }

        if ($value instanceof \Stringable) {
            return ['stringable' => $value::class, 'value' => (string) $value];
        }

        if ($value instanceof \JsonSerializable) {
            try {
                return self::normalize($value->jsonSerialize(), $depth + 1);
            } catch (\Throwable) {
                return ['object' => $value::class];
            }
        }

        if (\is_object($value)) {
            return ['object' => $value::class];
        }

        return ['resource' => \get_resource_type($value)];
    }
}
