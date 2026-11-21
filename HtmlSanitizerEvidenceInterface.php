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

use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationCancellation;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationReport;

/**
 * Optional extension for consumers that need output and node evidence to be
 * committed as one transaction.
 *
 * The historical {@see HtmlSanitizerInterface} remains unchanged so existing
 * implementations and callers do not need to adopt the evidence API.
 */
interface HtmlSanitizerEvidenceInterface extends HtmlSanitizerInterface
{
    public function sanitizeWithEvidence(string $input, ?SanitizationCancellation $cancellation = null): SanitizationReport;

    public function sanitizeForWithEvidence(string $element, string $input, ?SanitizationCancellation $cancellation = null): SanitizationReport;
}
