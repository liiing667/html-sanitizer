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
 * Internal control-flow exception used while a report is still being staged.
 *
 * The public exception is created only after the transaction has been closed,
 * so callers can never observe a half-built report.
 *
 * @internal
 */
final class SanitizationAborted extends \RuntimeException
{
    public function __construct(
        private readonly SanitizationFailure $failure,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($failure->getFailureMessage(), $failure->getCode() ?? 0, $previous);
    }

    public function getFailure(): SanitizationFailure
    {
        return $this->failure;
    }
}
