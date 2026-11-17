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

use League\Uri\Exceptions\SyntaxError;
use League\Uri\UriString;

/**
 * @internal
 */
final class UrlSanitizer
{
    /**
     * Characters with no legitimate place in a URL: explicit-direction BiDi
     * formatting marks plus Unicode whitespace and the zero-width no-break
     * space. ASCII space is tolerated and percent-encoded by parse().
     *
     * The characters are matched as UTF-8 byte sequences: with the "u" modifier,
     * preg_match() returns false on malformed UTF-8, which percent-decoding can
     * produce, and the check would then be skipped.
     */
    private const DENIED_CHARS_PATTERN = '/[\t\n\x0B\f\r]'
        .'|\xC2[\x85\xA0]'                // U+0085, U+00A0
        .'|\xE1\x9A\x80'                  // U+1680
        .'|\xE2\x80[\x80-\x8A\xA8-\xAF]'  // U+2000-U+200A, U+2028-U+202F
        .'|\xE2\x81[\x9F\xA6-\xA9]'       // U+205F, U+2066-U+2069
        .'|\xE3\x80\x80'                  // U+3000
        .'|\xEF\xBB\xBF/';                // U+FEFF

    /**
     * Sanitizes a given URL string.
     *
     * In addition to ensuring $input is a valid URL, this sanitizer checks that:
     *   * the URL's host is allowed ;
     *   * the URL's scheme is allowed ;
     *   * the URL is allowed to be relative if it is ;
     *
     * It also transforms the URL to HTTPS if requested.
     */
    public static function sanitize(?string $input, ?array $allowedSchemes = null, bool $forceHttps = false, ?array $allowedHosts = null, bool $allowRelative = false): ?string
    {
        return self::decide($input, $allowedSchemes, $forceHttps, $allowedHosts, $allowRelative)->getSanitizedUrl();
    }

    /**
     * Takes an explainable text-layer decision on a given URL string.
     *
     * The decision only judges the URL itself against the scheme, host and
     * relative-URL policy; it never considers the element or attribute that
     * embeds it. Attribute-layer sanitizers can compose it with their own
     * policy to decide what to do with the surrounding markup.
     */
    public static function decide(?string $input, ?array $allowedSchemes = null, bool $forceHttps = false, ?array $allowedHosts = null, bool $allowRelative = false): UrlDecision
    {
        if (!$input) {
            return UrlDecision::reject(UrlRejectionReason::Malformed);
        }

        // Schemes that browsers parse per WHATWG, where the authority is optional
        // and the number of slashes is not significant, must carry exactly the two
        // slashes RFC 3986 requires; otherwise the host seen by the browser is not
        // the host this sanitizer checks against the allowed hosts. The same holds
        // for scheme-relative URLs: "///host" is a path for RFC 3986 but an
        // authority for browsers.
        if (false !== strpbrk($input, '\\') || preg_match('~^(?:https?|ftp|wss?):(?!//[^/])|^///~i', $input)) {
            return UrlDecision::reject(UrlRejectionReason::InvalidStructure);
        }

        // Denied characters are reported separately from generic parse errors so
        // that consumers can tell a visual-spoofing attempt from a malformed URL.
        if (self::containsDeniedCharacters($input)) {
            return UrlDecision::reject(UrlRejectionReason::DeniedCharacter);
        }

        $url = self::parse($input);

        // Malformed URL
        if (!$url || !\is_array($url)) {
            // parse() also fails on denied characters hidden behind percent-encoding;
            // parse the raw components once more to distinguish that case from a
            // generic syntax error so the rejection reason stays explainable.
            try {
                $components = UriString::parse($input);
                if (isset($components['scheme'])) {
                    $components['scheme'] = strtolower($components['scheme']);
                }

                if (self::containsEncodedDeniedCharacters($components)) {
                    return UrlDecision::reject(UrlRejectionReason::DeniedCharacter);
                }
            } catch (SyntaxError) {
                // The URL is genuinely malformed rather than carrying denied characters
            }

            return UrlDecision::reject(UrlRejectionReason::Malformed);
        }

        // No scheme and relative not allowed
        if (!$allowRelative && !$url['scheme']) {
            return UrlDecision::reject(UrlRejectionReason::RelativeNotAllowed);
        }

        // Forbidden scheme
        if ($url['scheme'] && null !== $allowedSchemes && !\in_array($url['scheme'], $allowedSchemes, true)) {
            return UrlDecision::reject(UrlRejectionReason::SchemeNotAllowed);
        }

        // A view-source URL wraps another URL, which has to pass the same checks;
        // browsers reject a view-source URL wrapped in another one
        if ('view-source' === $url['scheme']) {
            $nested = substr($input, \strlen('view-source:'));

            if (0 === strncasecmp($nested, 'view-source:', 12)) {
                return UrlDecision::reject(UrlRejectionReason::InvalidStructure);
            }

            $nestedDecision = self::decide($nested, $allowedSchemes, $forceHttps, $allowedHosts, $allowRelative);

            if (!$nestedDecision->isAccepted()) {
                return $nestedDecision;
            }

            return UrlDecision::accept('view-source:'.$nestedDecision->getSanitizedUrl());
        }

        // If the scheme used is not supposed to have a host, do not check the host
        if (!self::isHostlessScheme($url['scheme'])) {
            // An empty authority is never a valid destination, and rebuilding the URL
            // around it changes the number of slashes in front of the path, which
            // browsers would then read as an authority
            if ('' === $url['host']) {
                return UrlDecision::reject(UrlRejectionReason::InvalidStructure);
            }

            // No host and relative not allowed; "0" is a host, not a missing one
            if (!$allowRelative && null === $url['host']) {
                return UrlDecision::reject(UrlRejectionReason::RelativeNotAllowed);
            }

            // Forbidden host
            if (null !== $url['host'] && null !== $allowedHosts && !self::isAllowedHost($url['host'], $allowedHosts)) {
                return UrlDecision::reject(UrlRejectionReason::HostNotAllowed);
            }
        }

        // Force HTTPS
        if ($forceHttps && 'http' === $url['scheme']) {
            $url['scheme'] = 'https';
        }

        // In absence of a scheme and of an authority, RFC 3986 forbids a colon in the
        // first path segment, which would otherwise be read as a scheme. league/uri
        // enforces this in UriString::build(), but only since version 7.
        if (!$url['scheme'] && null === $url['host'] && preg_match('#^[^/:]*+:#', $url['path'])) {
            return UrlDecision::reject(UrlRejectionReason::InvalidStructure);
        }

        try {
            return UrlDecision::accept(UriString::build($url));
        } catch (SyntaxError) {
            return UrlDecision::reject(UrlRejectionReason::Malformed);
        }
    }

    /**
     * Parses a given URL and returns an array of its components.
     *
     * @return array{
     *     scheme:?string,
     *     user:?string,
     *     pass:?string,
     *     host:?string,
     *     port:?int,
     *     path:string,
     *     query:?string,
     *     fragment:?string
     * }|null
     */
    public static function parse(string $url): ?array
    {
        if (!$url) {
            return null;
        }

        try {
            // Reject explicit-direction BiDi formatting characters and non-space
            // whitespace: they have no legitimate place in a URL and enable
            // visual spoofing of the rendered href when the URL is later
            // embedded in HTML or decoded by a downstream consumer.
            if (self::containsDeniedCharacters($url)) {
                return null;
            }

            // Browsers tolerate spaces inside path/query/fragment by transparently
            // percent-encoding them. Mirror that behavior, but never inside the
            // scheme or authority (where spaces are illegal); the whitespace check
            // below rejects any space that didn't fit in the encoded slice.
            if (str_contains($url, ' ')) {
                if (str_starts_with($url, ' ')) {
                    return null;
                }

                if (false !== $i = strpos($url, '://')) {
                    $i += 3 + strcspn($url, '/?#', $i + 3);
                } elseif (str_starts_with($url, '//')) {
                    $i = 2 + strcspn($url, '/?#', 2);
                } elseif (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
                    // Hostless scheme (data:, mailto:, …): leave the URL untouched
                    // and let the whitespace check reject it.
                    $i = \strlen($url);
                } else {
                    $i = 0;
                }

                $url = substr($url, 0, $i).str_replace(' ', '%20', substr($url, $i));
            }

            if (preg_match('/\s/', $url)) {
                return null;
            }

            $parsedUrl = UriString::parse($url);
            if (isset($parsedUrl['scheme'])) {
                $parsedUrl['scheme'] = strtolower($parsedUrl['scheme']);
            }

            if (isset($parsedUrl['host']) && self::decodeUnreservedCharacters($parsedUrl['host']) !== $parsedUrl['host']) {
                return null;
            }

            if (self::containsEncodedDeniedCharacters($parsedUrl)) {
                return null;
            }

            return $parsedUrl;
        } catch (SyntaxError) {
            return null;
        }
    }

    /**
     * Checks a raw URL string for denied characters (BiDi formatting marks,
     * non-space whitespace, byte order mark, ...).
     */
    public static function containsDeniedCharacters(string $url): bool
    {
        return (bool) preg_match(self::DENIED_CHARS_PATTERN, $url);
    }

    /**
     * Checks the components of a parsed URL for denied characters reachable
     * through percent-encoding; otherwise the upfront raw check could be
     * bypassed by encoding them.
     *
     * Percent-encoded line breaks and tabs are legitimate in the query and
     * fragment of hostless schemes: RFC 6068 requires %0D%0A for line breaks
     * in the body of a mailto URL.
     *
     * @param array<string, mixed> $parsedUrl
     */
    public static function containsEncodedDeniedCharacters(array $parsedUrl): bool
    {
        $isHostless = self::isHostlessScheme($parsedUrl['scheme'] ?? null);

        foreach (['user', 'pass', 'host', 'path', 'query', 'fragment'] as $part) {
            if (!isset($parsedUrl[$part])) {
                continue;
            }

            $decoded = rawurldecode($parsedUrl[$part]);

            if ($isHostless && ('query' === $part || 'fragment' === $part)) {
                $decoded = str_replace(["\r", "\n", "\t"], '', $decoded);
            }

            if (preg_match(self::DENIED_CHARS_PATTERN, $decoded)) {
                return true;
            }
        }

        return false;
    }

    private static function isHostlessScheme(?string $scheme): bool
    {
        return \in_array($scheme, ['blob', 'chrome', 'data', 'file', 'geo', 'mailto', 'maps', 'tel', 'sms'], true);
    }

    private static function isAllowedHost(?string $host, array $allowedHosts): bool
    {
        if (null === $host) {
            return \in_array(null, $allowedHosts, true);
        }

        $parts = array_reverse(explode('.', strtolower($host)));

        foreach ($allowedHosts as $allowedHost) {
            if (self::matchAllowedHostParts($parts, array_reverse(explode('.', strtolower($allowedHost))))) {
                return true;
            }
        }

        return false;
    }

    private static function matchAllowedHostParts(array $uriParts, array $trustedParts): bool
    {
        // Check each chunk of the domain is valid
        foreach ($trustedParts as $key => $trustedPart) {
            if (!\array_key_exists($key, $uriParts) || $uriParts[$key] !== $trustedPart) {
                return false;
            }
        }

        return true;
    }

    /**
     * Implementation borrowed from League\Uri\Encoder::decodeUnreservedCharacters().
     */
    private static function decodeUnreservedCharacters(string $host): string
    {
        return preg_replace_callback(
            ',%(2[1-9A-Fa-f]|[3-7][0-9A-Fa-f]|61|62|64|65|66|7[AB]|5F),',
            static fn (array $matches): string => rawurldecode($matches[0]),
            $host
        );
    }
}
