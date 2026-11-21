<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Exception;

use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationFailure;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationReport;

/**
 * Raised by the string API when a sanitization transaction did not commit.
 *
 * The complete failure report is attached so callers can still identify the
 * exact input path, element, attribute, policy and sanitizer that failed.
 * The sanitized output is always empty in this case.
 */
final class SanitizationException extends \RuntimeException
{
    public function __construct(
        private readonly SanitizationReport $report,
        ?\Throwable $previous = null,
    ) {
        $failure = $report->getFailure();
        $previous ??= $report->getPreviousError();
        $code = $failure?->getCode() ?? 0;
        if (0 === $code && null !== $previous) {
            $code = (int) $previous->getCode();
        }

        parent::__construct(
            $failure?->getFailureMessage() ?? 'Sanitization transaction did not commit.',
            $code,
            $previous,
        );
    }

    public function getReport(): SanitizationReport
    {
        return $this->report;
    }

    public function getFailure(): ?SanitizationFailure
    {
        return $this->report->getFailure();
    }

    public function getNodePath(): ?string
    {
        return $this->getFailure()?->getNodePath();
    }

    public function getStage(): ?string
    {
        return $this->getFailure()?->getStage();
    }

    public function getPolicy(): ?string
    {
        return $this->getFailure()?->getPolicy();
    }

    public function getSanitizer(): ?string
    {
        return $this->getFailure()?->getSanitizer();
    }
}
