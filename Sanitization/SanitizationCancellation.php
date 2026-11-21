<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Sanitization;

/**
 * Cooperative cancellation token for a sanitization transaction.
 *
 * Cancellation is checked before each input node, before rendering and before
 * the report is committed. A cancelled transaction produces a failed report
 * with an empty output; it never publishes the partially built tree.
 */
final class SanitizationCancellation
{
    private bool $cancelled = false;
    private string $reason = 'Sanitization cancelled.';

    public function cancel(string $reason = 'Sanitization cancelled.'): void
    {
        $this->cancelled = true;
        $this->reason = $reason;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
