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
 * Explainable outcome of a text-layer URL decision.
 *
 * A decision is either accepted, in which case {@see UrlDecision::getSanitizedUrl()}
 * returns the normalized URL safe to write back, or rejected, in which case the
 * same getter returns null and {@see UrlDecision::getReason()} explains which
 * rule the URL failed.
 *
 * The decision is independent from the element or attribute that embeds the URL:
 * attribute-layer sanitizers compose it with their own policy to decide what to
 * do with the surrounding markup.
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
final class UrlDecision
{
    /**
     * @param string|null              $sanitizedUrl The normalized URL when accepted, null otherwise
     * @param UrlRejectionReason|null  $reason       The rejection reason, or null when accepted
     */
    private function __construct(
        private readonly ?string $sanitizedUrl,
        private readonly ?UrlRejectionReason $reason,
    ) {
    }

    public static function accept(string $sanitizedUrl): self
    {
        return new self($sanitizedUrl, null);
    }

    public static function reject(UrlRejectionReason $reason): self
    {
        return new self(null, $reason);
    }

    public function isAccepted(): bool
    {
        return null !== $this->sanitizedUrl;
    }

    public function getSanitizedUrl(): ?string
    {
        return $this->sanitizedUrl;
    }

    public function getReason(): ?UrlRejectionReason
    {
        return $this->reason;
    }
}
