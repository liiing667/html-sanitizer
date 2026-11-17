<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer;

use Symfony\Component\HtmlSanitizer\TextSanitizer\UrlRejectionReason;

/**
 * Explainable outcome of an attribute-layer sanitization decision.
 *
 * The decision composes a text-layer URL decision with the element-level
 * policy: it carries both what to do with the element/attribute and, when the
 * embedded URL was rejected, the {@see UrlRejectionReason} explaining why.
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
final class AttributeDecision
{
    private function __construct(
        private readonly AttributeAction $action,
        private readonly ?string $sanitizedValue,
        private readonly ?UrlRejectionReason $reason,
    ) {
    }

    /**
     * The attribute value was accepted and must be written back on the element.
     */
    public static function keep(string $sanitizedValue): self
    {
        return new self(AttributeAction::KeepElement, $sanitizedValue, null);
    }

    /**
     * The attribute must be removed while the element (and its text) is retained.
     */
    public static function removeAttribute(UrlRejectionReason $reason): self
    {
        return new self(AttributeAction::RemoveAttribute, null, $reason);
    }

    /**
     * The whole element, including its children, must be removed.
     */
    public static function dropElement(UrlRejectionReason $reason): self
    {
        return new self(AttributeAction::DropElement, null, $reason);
    }

    /**
     * Bridges sanitizers still implementing the legacy sanitizeAttribute()
     * contract: a value keeps the attribute while null removes it but retains
     * the element and its children.
     */
    public static function fromLegacyResult(?string $sanitizedValue): self
    {
        return null === $sanitizedValue
            ? self::removeAttribute(UrlRejectionReason::Malformed)
            : self::keep($sanitizedValue);
    }

    public function getAction(): AttributeAction
    {
        return $this->action;
    }

    public function getSanitizedValue(): ?string
    {
        return $this->sanitizedValue;
    }

    public function getReason(): ?UrlRejectionReason
    {
        return $this->reason;
    }
}
