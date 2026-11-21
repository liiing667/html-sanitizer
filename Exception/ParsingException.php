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

use Symfony\Component\HtmlSanitizer\Parser\ParseReport;

/**
 * Reports a failure that happened while accepting a parsed document.
 *
 * The attached parse report keeps the input digest, parser generation,
 * document identity, node count and the stage that failed. This makes a
 * failed parse safely retryable without exposing a partially parsed document.
 */
final class ParsingException extends \RuntimeException
{
    public const STAGE_PARSE = 'parse';
    public const STAGE_VISIT = 'visit';

    public function __construct(
        private readonly string $stage,
        string $message,
        private readonly ?ParseReport $report = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('HTML parsing failed during the "%s" stage: %s', $stage, $message), 0, $previous);
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function getReport(): ?ParseReport
    {
        return $this->report;
    }

    public function getInputDigest(): ?string
    {
        return $this->report?->inputDigest;
    }

    public function getGeneration(): ?int
    {
        return $this->report?->generation;
    }

    public function getDocumentId(): ?string
    {
        return $this->report?->documentId;
    }

    public function getNodeCount(): int
    {
        return $this->report?->nodeCount ?? 0;
    }
}
