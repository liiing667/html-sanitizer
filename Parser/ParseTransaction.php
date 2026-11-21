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
 * Mutable state of one parse transaction.
 *
 * The transaction freezes the input digest and the parser generation before
 * any DOM work starts. The DOM document identity is frozen as soon as the
 * document exists, and the transaction can only be committed once the parser
 * has fully consumed the input. A partial transaction never produces a report
 * with committed=true.
 *
 * @internal
 */
final class ParseTransaction
{
    private string $stage = ParseReport::STAGE_STARTED;
    private \Dom\Document|\DOMDocument|null $document = null;
    private ?string $documentId = null;
    private ?int $documentObjectId = null;
    private readonly string $identityToken;
    private int $nodeCount = 0;
    private bool $committed = false;
    private ?string $reason = null;

    public function __construct(
        private readonly string $parserId,
        private readonly int $generation,
        private readonly string $inputDigest,
        private readonly string $context,
        private readonly bool $truncated = false,
    ) {
        // Freeze a candidate identity at the very beginning of the parse. It
        // remains unique across parser restarts even when the generation
        // counter starts again from zero.
        $this->identityToken = bin2hex(random_bytes(8));
    }

    public function freezeDocument(\Dom\Document|\DOMDocument $document): void
    {
        if (null !== $this->document && $this->document !== $document) {
            throw new \LogicException('A parse transaction cannot switch to another DOM document.');
        }

        $this->document = $document;
        $this->documentId ??= \sprintf('%s:%d:%s', $this->parserId, $this->generation, $this->identityToken);
        $this->documentObjectId = \spl_object_id($document);
    }

    public function getDocument(): \Dom\Document|\DOMDocument|null
    {
        return $this->document;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function getDocumentId(): ?string
    {
        return $this->documentId;
    }

    public function getInputDigest(): string
    {
        return $this->inputDigest;
    }

    public function recordNodeCount(int $nodeCount): void
    {
        $this->nodeCount = max(0, $nodeCount);
    }

    public function markParsed(): void
    {
        if (ParseReport::STAGE_STARTED === $this->stage) {
            $this->stage = ParseReport::STAGE_PARSED;
        }
    }

    public function markEmpty(): void
    {
        $this->stage = ParseReport::STAGE_EMPTY;
    }

    public function cancel(?string $reason = null): void
    {
        $this->committed = false;
        $this->stage = ParseReport::STAGE_CANCELLED;
        $this->reason = $reason;
    }

    public function fail(\Throwable|string $error): void
    {
        $this->committed = false;
        $this->stage = ParseReport::STAGE_FAILED;
        $this->reason = $error instanceof \Throwable ? $error->getMessage() : $error;
    }

    public function commit(): void
    {
        if (ParseReport::STAGE_CANCELLED === $this->stage || ParseReport::STAGE_FAILED === $this->stage) {
            throw new \LogicException('A cancelled or failed parse transaction cannot be committed.');
        }

        if (null === $this->documentId) {
            throw new \LogicException('A parse transaction cannot be committed before its DOM document identity is frozen.');
        }

        $this->committed = true;

        if (ParseReport::STAGE_EMPTY !== $this->stage) {
            $this->stage = ParseReport::STAGE_COMMITTED;
        }
    }

    public function isCommitted(): bool
    {
        return $this->committed;
    }

    public function isCancelled(): bool
    {
        return ParseReport::STAGE_CANCELLED === $this->stage;
    }

    public function report(): ParseReport
    {
        return new ParseReport(
            $this->parserId,
            $this->generation,
            $this->inputDigest,
            $this->context,
            $this->stage,
            $this->documentId,
            $this->documentObjectId,
            $this->nodeCount,
            $this->truncated,
            $this->committed,
            $this->reason,
        );
    }
}
