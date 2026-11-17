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

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Attribute sanitizers that expose an explainable, composable decision on
 * the element and attribute they process.
 *
 * Unlike the legacy {@see AttributeSanitizerInterface::sanitizeAttribute()}
 * contract, which can only remove the attribute, the decision can also request
 * the removal of the whole element and carries the rejection reason returned
 * by the text-layer URL decision.
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
interface ExplainableAttributeSanitizerInterface extends AttributeSanitizerInterface
{
    /**
     * Takes an explainable, composable decision on a given attribute.
     */
    public function decideAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): AttributeDecision;
}
