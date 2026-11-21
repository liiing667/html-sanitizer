<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Parser;

/**
 * Immutable evidence about one parser transaction.
 *
 * A report is published only when the parser has reached a stable outcome:
 * either the complete document identity has been committed or the transaction
 * failed/was cancelled before an identity was published.
 */
final readonly class ParseReport implements \JsonSerializable
{
    public const STAGE_STARTED = 'started';
    public const STAGE_PARSED = 'parsed';
    public const STAGE_COMMITTED = 'committed';
    public const STAGE_EMPTY = 'empty';
    public const STAGE_FAILED = 'failed';
    public const STAGE_CANCELLED = 'cancelled';

    public function __construct(
        public string $parserId,
        public int $generation,
        public string $inputDigest,
        public string $context,
        public string $stage,
        public ?string $documentId = null,
        public ?int $documentObjectId = null,
        public int $nodeCount = 0,
        public bool $truncated = false,
        public bool $committed = false,
        public ?string $reason = null,
    ) {
    }

    public function isCommitted(): bool
    {
        return $this->committed;
    }

    public function getParserId(): string
    {
        return $this->parserId;
    }

    public function getContext(): string
    {
        return $this->context;
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
        return $this->documentObjectId;
    }

    public function getInputDigest(): string
    {
        return $this->inputDigest;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function getParserGeneration(): int
    {
        return $this->generation;
    }

    public function getNodeCount(): int
    {
        return $this->nodeCount;
    }

    public function getStage(): string
    {
        return $this->stage;
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
            'parser_id' => $this->parserId,
            'generation' => $this->generation,
            'input_digest' => $this->inputDigest,
            'context' => $this->context,
            'stage' => $this->stage,
            'document_id' => $this->documentId,
            'document_object_id' => $this->documentObjectId,
            'node_count' => $this->nodeCount,
            'truncated' => $this->truncated,
            'committed' => $this->committed,
            'reason' => $this->reason,
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
