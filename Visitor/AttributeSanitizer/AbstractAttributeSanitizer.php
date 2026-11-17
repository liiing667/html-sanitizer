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
 * Bridges the legacy sanitizeAttribute() contract onto the explainable
 * decideAttribute() one.
 *
 * Extending sanitizers only have to implement sanitizeAttribute(): a sanitized
 * value keeps the attribute while null removes it but retains the element and
 * its children. Sanitizers that need to drop the whole element or to expose a
 * rejection reason should override decideAttribute().
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
abstract class AbstractAttributeSanitizer implements ExplainableAttributeSanitizerInterface
{
    public function decideAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): AttributeDecision
    {
        return AttributeDecision::fromLegacyResult(
            $this->sanitizeAttribute($element, $attribute, $value, $config)
        );
    }
}
