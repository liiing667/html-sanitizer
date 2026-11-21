<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Exception;

/**
 * Thrown when an attribute sanitizer fails while visiting a node.
 *
 * Attribute values are staged before being applied to the sanitized tree. When
 * this exception is thrown, no output is rendered, so a retry starts from the
 * complete input document instead of a partially modified tree.
 */
final class AttributeSanitizationException extends \RuntimeException
{
    public const STAGE = 'attribute-sanitization';

    public function __construct(
        private readonly string $elementName,
        private readonly string $attributeName,
        private readonly string $nodePath,
        private readonly string $sanitizer,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf(
            'Failed to sanitize attribute "%s" of <%s> at node "%s" during the "%s" stage: %s',
            $attributeName,
            $elementName,
            $nodePath,
            self::STAGE,
            $previous?->getMessage() ?? 'unknown error',
        ), $previous?->getCode() ?? 0, $previous);
    }

    public function getElementName(): string
    {
        return $this->elementName;
    }

    public function getElement(): string
    {
        return $this->elementName;
    }

    public function getAttributeName(): string
    {
        return $this->attributeName;
    }

    public function getAttribute(): string
    {
        return $this->attributeName;
    }

    public function getNodePath(): string
    {
        return $this->nodePath;
    }

    public function getSanitizer(): string
    {
        return $this->sanitizer;
    }

    public function getStage(): string
    {
        return self::STAGE;
    }

    public function getFailureStage(): string
    {
        return self::STAGE;
    }
}
