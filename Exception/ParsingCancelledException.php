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
 * Thrown by a parser when the current parse is explicitly cancelled.
 *
 * The sanitizer turns this into a non-committed report and never publishes a
 * partially parsed document. A later parse starts from a new transaction.
 */
final class ParsingCancelledException extends \RuntimeException
{
}
