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

use Symfony\Component\HtmlSanitizer\Exception\ParsingCancelledException;
use Symfony\Component\HtmlSanitizer\Exception\ParsingException;

/**
 * Parser using PHP 8.4's new Dom API.
 */
final class NativeParser implements ParserInterface
{
    /**
     * Wrapping the input does not work for these elements: the parser ignores
     * their start tag, turns "image" into "img" or reads "</plaintext>" as text.
     */
    private const SPECIAL_CONTEXTS = [
        'caption',
        'col',
        'colgroup',
        'image',
        'plaintext',
        'tbody',
        'td',
        'tfoot',
        'th',
        'thead',
        'tr',
    ];

    private string $parserId;
    private int $generation = 0;
    private ?ParseReport $lastReport = null;
    private ?ParseTransaction $activeTransaction = null;
    private ?int $cancelledGeneration = null;
    private ?string $cancelledReason = null;
    private ?string $cancelNextReason = null;

    /**
     * Documents already produced by this parser, keyed by object id.
     *
     * The native parser normally creates a fresh document for every call. This
     * registry is a defensive gate against a runtime that reuses a document
     * object for another input: such a document is never committed again.
     *
     * @var array<int, array{0: object, 1: string|null}>
     */
    private array $documentDigests = [];

    public function __construct(?string $parserId = null)
    {
        $this->parserId = $parserId ?? bin2hex(random_bytes(16));
    }

    public function parse(string $html, string $context = 'body', bool $truncated = false): ?\Dom\Node
    {
        return $this->parseInternal($html, $context, $truncated);
    }

    /**
     * Parses a document and exposes whether the input was truncated before it
     * was handed over to the parser.
     *
     * The truncation flag is evidence only: the parser always builds a fresh
     * DOM document for this call and never reuses a node from an earlier one.
     */
    public function parseWithReport(string $html, string $context = 'body', bool $truncated = false): ?\Dom\Node
    {
        return $this->parseInternal($html, $context, $truncated);
    }

    public function getLastReport(): ?ParseReport
    {
        return $this->lastReport;
    }

    public function getLastParseReport(): ?ParseReport
    {
        return $this->lastReport;
    }

    public function getParserId(): string
    {
        return $this->parserId;
    }

    /**
     * Returns the generation of the most recently started parse.
     */
    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function getParserGeneration(): int
    {
        return $this->generation;
    }

    public function getDocumentIdentity(): ?string
    {
        return $this->lastReport?->documentId;
    }

    public function getLastDocumentIdentity(): ?string
    {
        return $this->lastReport?->documentId;
    }

    public function getLastInputDigest(): ?string
    {
        return $this->lastReport?->inputDigest;
    }

    public function getLastDocumentId(): ?string
    {
        return $this->lastReport?->documentId;
    }

    public function getLastDocumentObjectId(): ?int
    {
        return $this->lastReport?->documentObjectId;
    }

    public function getLastNodeCount(): int
    {
        return $this->lastReport?->nodeCount ?? 0;
    }

    /**
     * Cancels the active parse, or the next parse when no parse is running.
     *
     * This is useful when a caller shares a parser between fibers and wants to
     * abort a parse that has already started. The cancelled transaction is
     * published as a non-committed report and returns null.
     */
    public function cancel(?string $reason = null): void
    {
        if (null !== $this->activeTransaction) {
            $this->cancelledGeneration = $this->activeTransaction->getGeneration();
            $this->cancelledReason = $reason;

            return;
        }

        $this->cancelNextReason = $reason ?? 'cancelled';
    }

    public function cancelNextParse(?string $reason = null): void
    {
        $this->cancelNextReason = $reason ?? 'cancelled';
    }

    private function parseInternal(string $html, string $context, bool $truncated): ?\Dom\Node
    {
        // Freeze the parser generation and input digest before any DOM work.
        $transaction = new ParseTransaction(
            $this->parserId,
            ++$this->generation,
            hash('sha256', $html),
            $context,
            $truncated,
        );

        $previousTransaction = $this->activeTransaction;
        $this->activeTransaction = $transaction;

        try {
            if (null !== $this->cancelNextReason) {
                $reason = $this->cancelNextReason;
                $this->cancelNextReason = null;
                $transaction->cancel($reason);

                return $this->publish($transaction, null);
            }

            if ($this->isCancelled($transaction)) {
                $transaction->cancel($this->cancelledReason ?? 'cancelled');

                return $this->publish($transaction, null);
            }

            [$document, $element] = $this->createDocument($html, $context, $transaction);

            if ($this->documentWasReused($document, $transaction->getInputDigest())) {
                $transaction->fail('The parser reused a DOM document for a different input.');

                return $this->publish($transaction, null);
            }

            if (!$this->isCurrent($transaction)) {
                $transaction->cancel('superseded by a newer parse');

                return $this->publish($transaction, null);
            }

            if ($this->isCancelled($transaction)) {
                $transaction->cancel($this->cancelledReason ?? 'cancelled');

                return $this->publish($transaction, null);
            }

            if (null === $element || !$element->hasChildNodes()) {
                $transaction->recordNodeCount(null === $element ? 0 : self::countNodes($element));
                $transaction->markEmpty();
                $transaction->commit();

                return $this->publish($transaction, null);
            }

            // The returned node must belong to the exact document created by
            // this transaction. A node from another document is never accepted.
            if ($element->ownerDocument !== $document) {
                throw new \LogicException('The parsed node does not belong to the DOM document created for this parse.');
            }

            $transaction->recordNodeCount(self::countNodes($element));
            $transaction->markParsed();

            if (!$this->isCurrent($transaction)) {
                $transaction->cancel('superseded by a newer parse');

                return $this->publish($transaction, null);
            }

            if ($this->isCancelled($transaction)) {
                $transaction->cancel($this->cancelledReason ?? 'cancelled');

                return $this->publish($transaction, null);
            }

            // Commit only after the whole input has been consumed and the
            // document identity has been verified.
            $transaction->commit();

            return $this->publish($transaction, $element);
        } catch (ParsingCancelledException $e) {
            $transaction->cancel($e->getMessage() ?: 'cancelled');

            return $this->publish($transaction, null);
        } catch (ParsingException $e) {
            $transaction->fail($e);
            $this->publish($transaction, null);

            throw $e;
        } catch (\Throwable $e) {
            $transaction->fail($e);
            $this->publish($transaction, null);

            throw new ParsingException(ParsingException::STAGE_PARSE, $e->getMessage(), $transaction->report(), $e);
        } finally {
            $this->activeTransaction = $previousTransaction;

            if ($this->cancelledGeneration === $transaction->getGeneration()) {
                $this->cancelledGeneration = null;
                $this->cancelledReason = null;
            }
        }
    }

    private function createDocument(string $html, string $context, ParseTransaction $transaction): array
    {
        if (\in_array($context, self::SPECIAL_CONTEXTS, true)) {
            $document = \Dom\HTMLDocument::createEmpty();
            $transaction->freezeDocument($document);

            $element = $document->createElement($context);
            $element->innerHTML = $html;

            return [$document, $element];
        }

        $document = @\Dom\HTMLDocument::createFromString(\sprintf('<!DOCTYPE html><%s>%s</%1$s>', $context, $html), 0, 'UTF-8');

        if (!$document instanceof \Dom\HTMLDocument) {
            throw new \LogicException('The native HTML parser did not return a DOM document.');
        }

        $transaction->freezeDocument($document);

        return [$document, $document->getElementsByTagName($context)->item(0)];
    }

    private function isCurrent(ParseTransaction $transaction): bool
    {
        return $transaction->getGeneration() === $this->generation;
    }

    private function documentWasReused(\Dom\Document|\DOMDocument $document, string $inputDigest): bool
    {
        $objectId = \spl_object_id($document);

        if (isset($this->documentDigests[$objectId])) {
            [$knownDocument, $knownDigest] = $this->documentDigests[$objectId];

            if ($knownDocument === $document) {
                if (null === $knownDigest || $knownDigest !== $inputDigest) {
                    $this->documentDigests[$objectId] = [$document, null];

                    return true;
                }

                return false;
            }
        }

        $this->documentDigests[$objectId] = [$document, $inputDigest];

        if (16 < \count($this->documentDigests)) {
            $oldest = array_key_first($this->documentDigests);
            unset($this->documentDigests[$oldest]);
        }

        return false;
    }

    private function isCancelled(ParseTransaction $transaction): bool
    {
        return $this->cancelledGeneration === $transaction->getGeneration();
    }

    private function publish(ParseTransaction $transaction, ?\Dom\Node $node): ?\Dom\Node
    {
        // A nested parse owns the published report once it starts. The older
        // transaction remains a local, non-committed report.
        if ($this->isCurrent($transaction)) {
            $this->lastReport = $transaction->report();
        }

        return $node;
    }

    private static function countNodes(\Dom\Node|\DOMNode $node, ?\SplObjectStorage $seen = null): int
    {
        $seen ??= new \SplObjectStorage();

        if ($seen->contains($node)) {
            return 0;
        }

        $seen->attach($node);
        $count = 1;

        foreach ($node->childNodes ?? [] as $child) {
            $count += self::countNodes($child, $seen);
        }

        return $count;
    }
}
