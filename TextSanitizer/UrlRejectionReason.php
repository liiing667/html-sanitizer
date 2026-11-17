<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\TextSanitizer;

/**
 * Explains why a URL was rejected by the {@see UrlSanitizer}.
 *
 * The reasons only describe the text-layer decision: whether the raw URL string
 * complies with the requested scheme, host and relative-URL policy. They carry
 * no information about the element or attribute that embeds the URL.
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
enum UrlRejectionReason: string
{
    /**
     * The input is empty or cannot be parsed as a URL.
     */
    case Malformed = 'malformed';

    /**
     * The URL has no scheme while relative URLs are not allowed.
     */
    case RelativeNotAllowed = 'relative_not_allowed';

    /**
     * The URL scheme is not part of the allowed schemes.
     */
    case SchemeNotAllowed = 'scheme_not_allowed';

    /**
     * The URL host is not part of the allowed hosts, or the URL is missing
     * the host a non-hostless scheme requires.
     */
    case HostNotAllowed = 'host_not_allowed';

    /**
     * The URL contains characters denied by the sanitizer (whitespace,
     * BiDi formatting marks, ...), either directly or percent-encoded.
     */
    case DeniedCharacter = 'denied_character';

    /**
     * The URL cannot be rebuilt after normalization, for instance because
     * a scheme-less first path segment contains a colon.
     */
    case InvalidStructure = 'invalid_structure';
}
