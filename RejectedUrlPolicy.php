<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer;

/**
 * Declares what the sanitizer should do when a URL attribute fails the URL
 * policy (forbidden scheme or host, disallowed relative URL, malformed value, ...).
 *
 * The policy only applies once the element itself is allowed: element-level
 * allow/block/drop rules still take precedence.
 */
enum RejectedUrlPolicy: string
{
    /**
     * Remove the offending URL attribute (for instance href or src) but keep
     * the element and its children, including the visible link text.
     *
     * This is the historical default behavior.
     */
    case RemoveAttribute = 'remove_attribute';

    /**
     * Remove the whole element, including its children, so that a rejected
     * link cannot leave behind text spoofing the destination it pretended
     * to point to.
     */
    case DropElement = 'drop_element';
}
