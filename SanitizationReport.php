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
 * Immutable evidence about one sanitization transaction.
 *
 * The report contains both the sanitizer decision and the parser evidence it
 * was based on: input digest, parser generation, document identity, parse
 * stage and node count. The output is part of the report so a consumer can
 * verify that it only received a committed document.
 */
final readonly class SanitizationReport implements \JsonSerializable
{
    public const STAGE_TEXT = 'text';
    public const STAGE_INVALID_UTF8 = 'invalid-utf8';
    public const STAGE_EMPTY = 'empty';
    public const STAGE_PLAIN_TEXT = 'plain-text';
    public const STAGE_TRUNCATED = 'truncated';
    public const STAGE_PARSE_FAILED = 'parse-failed';
    public const STAGE_STALE_DOCUMENT = 'stale-document';
    public const STAGE_SANITIZE_FAILED = 'sanitize-failed';
    public const STAGE_CANCELLED = 'cancelled';
    public const STAGE_COMMITTED = 'committed';

    public function __construct(
        public string $element,
        public string $context,
        public string $stage,
        public string $originalInputDigest,
        public string $inputDigest,
        public string $output,
        public string $outputDigest,
        public bool $truncated,
        public bool $committed,
        public ?ParseReport $parseReport = null,
        public ?string $documentId = null,
        public int $nodeCount = 0,
        public int $outputNodeCount = 0,
        public ?string $reason = null,
    ) {
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getOutputDigest(): string
    {
        return $this->outputDigest;
    }

    public function isCommitted(): bool
    {
        return $this->committed;
    }

    public function getElement(): string
    {
        return $this->element;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function getParseStage(): ?string
    {
        return $this->parseReport?->stage;
    }

    public function getInputDigest(): string
    {
        return $this->inputDigest;
    }

    public function getOriginalInputDigest(): string
    {
        return $this->originalInputDigest;
    }

    public function getDocumentId(): ?string
    {
        return $this->documentId;
    }

    public function getDocumentIdentity(): ?string
    {
        return $this->documentId;
    }

    public function getDocumentObjectId(): ?int
    {
        return $this->parseReport?->documentObjectId;
    }

    public function getNodeCount(): int
    {
        return $this->nodeCount;
    }

    public function getOutputNodeCount(): int
    {
        return $this->outputNodeCount;
    }

    public function getParserGeneration(): ?int
    {
        return $this->parseReport?->generation;
    }

    public function getParserId(): ?string
    {
        return $this->parseReport?->parserId;
    }

    public function getGeneration(): ?int
    {
        return $this->parseReport?->generation;
    }

    public function getParseReport(): ?ParseReport
    {
        return $this->parseReport;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'element' => $this->element,
            'context' => $this->context,
            'stage' => $this->stage,
            'original_input_digest' => $this->originalInputDigest,
            'input_digest' => $this->inputDigest,
            'output_digest' => $this->outputDigest,
            'truncated' => $this->truncated,
            'committed' => $this->committed,
            'document_id' => $this->documentId,
            'document_object_id' => $this->parseReport?->documentObjectId,
            'node_count' => $this->nodeCount,
            'output_node_count' => $this->outputNodeCount,
            'reason' => $this->reason,
            'parse_report' => $this->parseReport?->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
