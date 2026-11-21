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

// Backwards-friendly name for consumers that refer to a report as a result.
class_alias(SanitizationReport::class, __NAMESPACE__.'\SanitizationResult');
