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

/**
 * Describes what the {@see \Symfony\Component\HtmlSanitizer\Visitor\DomVisitor}
 * should do with an element following an attribute-layer decision.
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
enum AttributeAction
{
    /**
     * Keep the element (and its children) and write the sanitized attribute
     * value back on it.
     */
    case KeepElement;

    /**
     * Keep the element and its children, including the link text, but remove
     * the attribute entirely. This is the historical behavior applied when a
     * URL attribute fails the URL policy.
     */
    case RemoveAttribute;

    /**
     * Remove the whole element, including its children. Used when the policy
     * asks for a full removal so that a rejected link cannot leave behind a
     * text that spoofs a trusted destination.
     */
    case DropElement;
}
