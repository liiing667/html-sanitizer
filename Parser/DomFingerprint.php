<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Parser;

/**
 * Builds a deterministic digest of a parsed DOM subtree.
 *
 * Attribute order is normalized so semantically identical documents produced
 * by different parsers get the same fingerprint. The fingerprint is used to
 * detect a document that was mutated between two sanitization attempts.
 *
 * @internal
 */
final class DomFingerprint
{
    public static function of(\Dom\Node|\DOMNode $node): string
    {
        $context = hash_init('sha256');
        self::hashNode($node, $context, new \SplObjectStorage());

        return hash_final($context);
    }

    /**
     * @param \HashContext $context
     */
    private static function hashNode(\Dom\Node|\DOMNode $node, $context, \SplObjectStorage $seen): void
    {
        hash_update($context, $node->nodeName."\0");

        if ($seen->contains($node)) {
            hash_update($context, "cycle\0");

            return;
        }

        $seen->attach($node);

        if ($node instanceof \Dom\Element || $node instanceof \DOMElement) {
            $attributes = [];

            if ($attributeNodes = $node->attributes?->getIterator()) {
                foreach ($attributeNodes as $attribute) {
                    $attributes[] = $attribute->name.'='.$attribute->value;
                }
            }

            sort($attributes, \SORT_STRING);
            hash_update($context, implode("\0", $attributes)."\0");
        } else {
            hash_update($context, ($node->textContent ?? $node->nodeValue ?? '')."\0");
        }

        foreach ($node->childNodes ?? [] as $child) {
            self::hashNode($child, $context, $seen);
        }
    }
}
