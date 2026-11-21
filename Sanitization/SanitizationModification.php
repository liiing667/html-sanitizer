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

/**
 * A single auditable change made while sanitizing one input node.
 */
final class SanitizationModification
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $action,
        private readonly string $inputPath,
        private readonly string $policy,
        private readonly ?string $name = null,
        private readonly ?string $before = null,
        private readonly ?string $after = null,
        private readonly ?string $sanitizer = null,
        private readonly array $metadata = [],
    ) {
    }

    /**
     * One of "element", "attribute", "text", "comment" or "input".
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * One of "keep", "drop", "block", "rewrite", "force", "encode" or "truncate".
     */
    public function getAction(): string
    {
        return $this->action;
    }

    public function getModificationAction(): string
    {
        return $this->action;
    }

    public function getInputPath(): string
    {
        return $this->inputPath;
    }

    public function getNodePath(): string
    {
        return $this->inputPath;
    }

    public function getPolicy(): string
    {
        return $this->policy;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getAttribute(): ?string
    {
        return 'attribute' === $this->kind ? $this->name : null;
    }

    public function getAttributeName(): ?string
    {
        return $this->getAttribute();
    }

    public function getBefore(): ?string
    {
        return $this->before;
    }

    public function getAfter(): ?string
    {
        return $this->after;
    }

    public function getSanitizer(): ?string
    {
        return $this->sanitizer;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'action' => $this->action,
            'input_path' => $this->inputPath,
            'policy' => $this->policy,
            'name' => $this->name,
            'before' => $this->before,
            'after' => $this->after,
            'sanitizer' => $this->sanitizer,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['kind'] ?? 'unknown'),
            (string) ($data['action'] ?? 'unknown'),
            (string) ($data['input_path'] ?? 'document'),
            (string) ($data['policy'] ?? 'unknown'),
            isset($data['name']) ? (string) $data['name'] : null,
            isset($data['before']) ? (string) $data['before'] : null,
            isset($data['after']) ? (string) $data['after'] : null,
            isset($data['sanitizer']) ? (string) $data['sanitizer'] : null,
            \is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
