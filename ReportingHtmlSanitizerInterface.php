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

use Symfony\Component\HtmlSanitizer\Parser\ParseReport;

/**
 * Optional reporting API for sanitizers that expose their transaction evidence.
 *
 * The historical HtmlSanitizerInterface is intentionally unchanged: existing
 * implementations keep working, while consumers that need the input digest,
 * parser generation, document identity or committed output can opt in to this
 * interface.
 */
interface ReportingHtmlSanitizerInterface extends HtmlSanitizerInterface
{
    public function sanitizeWithReport(string $input): SanitizationReport;

    public function sanitizeForWithReport(string $element, string $input): SanitizationReport;

    public function getLastReport(): ?SanitizationReport;

    public function getLastParseReport(): ?ParseReport;
}
