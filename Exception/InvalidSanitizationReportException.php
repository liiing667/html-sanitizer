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

/**
 * Raised when a persisted report is incomplete or was modified after commit.
 */
final class InvalidSanitizationReportException extends \UnexpectedValueException
{
}
