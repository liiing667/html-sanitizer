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

use Symfony\Component\HtmlSanitizer\Exception\ParsingCancelledException;
use Symfony\Component\HtmlSanitizer\Exception\ParsingException;
use Symfony\Component\HtmlSanitizer\Parser\DomFingerprint;
use Symfony\Component\HtmlSanitizer\Parser\NativeParser;
use Symfony\Component\HtmlSanitizer\Parser\ParseReport;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;
use Symfony\Component\HtmlSanitizer\Reference\W3CReference;
use Symfony\Component\HtmlSanitizer\TextSanitizer\StringSanitizer;
use Symfony\Component\HtmlSanitizer\Visitor\DomVisitor;
use Symfony\Component\HtmlSanitizer\Visitor\Node\DocumentNode;

/**
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
final class HtmlSanitizer implements ReportingHtmlSanitizerInterface
{
    private ParserInterface $parser;

    /**
     * @var array<string, DomVisitor>
     */
    private array $domVisitors = [];

    private ?SanitizationReport $lastReport = null;
    private int $operation = 0;

    /**
     * Documents already used by a previous sanitization, keyed by object id.
     *
     * A document may be reused for an idempotent retry of the exact same input,
     * but never for different input. The registry is shared by all sanitizer
     * instances so restarting an instance cannot revive an old document.
     *
     * @var array<int, array{0: object, 1: string|null, 2: string|null}>
     */
    private static array $documentDigests = [];

    public function __construct(
        private HtmlSanitizerConfig $config,
        ?ParserInterface $parser = null,
    ) {
        $this->parser = $parser ?? new NativeParser();
    }

    public function sanitize(string $input): string
    {
        return $this->sanitizeForWithReport(W3CReference::CONTEXT_BODY, $input)->getOutput();
    }

    public function sanitizeFor(string $element, string $input): string
    {
        return $this->sanitizeForWithReport($element, $input)->getOutput();
    }

    /**
     * Sanitizes a body fragment and returns the complete transaction report.
     */
    public function sanitizeWithReport(string $input): SanitizationReport
    {
        return $this->sanitizeForWithReport(W3CReference::CONTEXT_BODY, $input);
    }

    /**
     * Sanitizes a fragment for a context and returns the complete transaction
     * report together with the exact output that was committed.
     */
    public function sanitizeForWithReport(string $element, string $input): SanitizationReport
    {
        $operation = ++$this->operation;
        $originalInputDigest = hash('sha256', $input);

        $requestedElement = StringSanitizer::htmlLower($element);
        $element = $requestedElement;
        $context = W3CReference::CONTEXTS_MAP[$element] ?? W3CReference::CONTEXT_BODY;
        $element = isset(W3CReference::BODY_ELEMENTS[$element]) ? $element : $context;
        $reportElement = $requestedElement;

        $truncated = false;

        // Prevent DOS attack induced by extremely long HTML strings
        if (-1 !== ($maxLength = $this->config->getMaxInputLength()) && \strlen($input) > $maxLength) {
            // Don't cut a multibyte character, which would make the input invalid UTF-8
            while (0 < $maxLength && 0x80 === (\ord($input[$maxLength]) & 0xC0)) {
                --$maxLength;
            }

            $input = substr($input, 0, $maxLength);
            $truncated = true;
        }

        $inputDigest = $truncated ? hash('sha256', $input) : $originalInputDigest;

        // Text context: early return with HTML encoding
        if (W3CReference::CONTEXT_TEXT === $context) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_TEXT,
                $originalInputDigest,
                $inputDigest,
                StringSanitizer::encodeHtmlEntities($input),
                $truncated,
                true,
                null,
                null,
                0,
                0,
            ));
        }

        // Other context: build a DOM visitor
        $this->domVisitors[$context] ??= $this->createDomVisitorForContext($context);

        // Only operate on valid UTF-8 strings. This is necessary to prevent cross
        // site scripting issues on Internet Explorer 6. Idea from Drupal (filter_xss).
        if (!$this->isValidUtf8($input)) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_INVALID_UTF8,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                null,
                null,
                0,
                0,
                'The input is not valid UTF-8.',
            ));
        }

        // Remove NULL character and HTML entities for null byte
        if (str_contains($input, \chr(0))) {
            $input = str_replace(\chr(0), '�', $input);
            $inputDigest = hash('sha256', $input);
        }

        if ('' === trim($input)) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_EMPTY,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                true,
                null,
                null,
                0,
                0,
            ));
        }

        // Plain text needs no parsing, only the normalization of line breaks that the parser would do
        if (W3CReference::CONTEXT_BODY === $element && $this->parser instanceof NativeParser && !str_contains($input, '<') && !str_contains($input, '&')) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_PLAIN_TEXT,
                $originalInputDigest,
                $inputDigest,
                StringSanitizer::encodeHtmlEntities(str_replace(["\r\n", "\r"], "\n", $input)),
                $truncated,
                true,
                null,
                null,
                0,
                0,
            ));
        }

        // A truncation that stops inside a tag or a quoted attribute would
        // otherwise let the HTML parser invent a partial node. Reject that
        // prefix instead of serializing a half document.
        if ($truncated && $this->truncationBreaksMarkup($input)) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_TRUNCATED,
                $originalInputDigest,
                $inputDigest,
                '',
                true,
                false,
                null,
                null,
                0,
                0,
                'The input was truncated inside an HTML tag or a quoted attribute.',
            ));
        }

        // Parse as HTML
        $parseReport = null;

        try {
            if ($this->parser instanceof NativeParser) {
                $parsed = $this->parser->parseWithReport($input, $element, $truncated);
                $parseReport = $this->parser->getLastReport();
            } else {
                $parsed = $this->parser->parse($input, $element);
            }
        } catch (ParsingCancelledException $e) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_CANCELLED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                null,
                null,
                0,
                0,
                $e->getMessage() ?: 'The HTML parse was cancelled.',
            ));
        } catch (ParsingException $e) {
            $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_PARSE_FAILED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                $e->getReport(),
                $e->getDocumentId(),
                $e->getNodeCount(),
                0,
                $e->getMessage(),
            ));

            throw $e;
        } catch (\Throwable $e) {
            $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_PARSE_FAILED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                null,
                null,
                0,
                0,
                $e->getMessage(),
            ));

            throw $e;
        }

        if (!$parsed) {
            if (null !== $parseReport) {
                $stage = match ($parseReport->stage) {
                    ParseReport::STAGE_CANCELLED => SanitizationReport::STAGE_CANCELLED,
                    ParseReport::STAGE_EMPTY => SanitizationReport::STAGE_EMPTY,
                    default => SanitizationReport::STAGE_PARSE_FAILED,
                };

                return $this->publishReport($operation, $this->createReport(
                    $reportElement,
                    $context,
                    $stage,
                    $originalInputDigest,
                    $inputDigest,
                    '',
                    $truncated,
                    $parseReport->isCommitted(),
                    $parseReport,
                    $parseReport->documentId,
                    $parseReport->nodeCount,
                    0,
                    $parseReport->reason,
                ));
            }

            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_PARSE_FAILED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                null,
                null,
                0,
                0,
                'The HTML parser returned no node.',
            ));
        }

        if (null !== $parseReport && !$parseReport->isCommitted()) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_PARSE_FAILED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                $parseReport,
                $parseReport->documentId,
                $parseReport->nodeCount,
                0,
                $parseReport->reason ?? 'The parser did not commit a complete document identity.',
            ));
        }

        $document = $parsed instanceof \Dom\Document || $parsed instanceof \DOMDocument
            ? $parsed
            : $parsed->ownerDocument;

        if (null === $document) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_PARSE_FAILED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                $parseReport,
                $parseReport?->documentId,
                $parseReport?->nodeCount ?? 0,
                0,
                'The parsed node has no owner document.',
            ));
        }

        if (null !== $parseReport?->documentObjectId && $parseReport->documentObjectId !== \spl_object_id($document)) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_PARSE_FAILED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                $parseReport,
                $parseReport->documentId,
                $parseReport->nodeCount,
                0,
                'The parser report belongs to a different DOM document.',
            ));
        }

        // NativeParser reports are trusted for a fresh document identity. For
        // custom parsers, fingerprint the returned DOM so a mutated cached tree
        // is rejected even when the input text is retried unchanged.
        if ($this->documentWasReused($document, $inputDigest, null === $parseReport ? $parsed : null)) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_STALE_DOCUMENT,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                $parseReport,
                $parseReport?->documentId ?? self::documentIdentity($document),
                $parseReport?->nodeCount ?? 0,
                0,
                'The parser returned a DOM document that belongs to a different input or was changed between attempts.',
            ));
        }

        $documentId = $parseReport?->documentId ?? self::documentIdentity($document);

        try {
            $sanitized = $this->domVisitors[$context]->acceptNode($parsed, $parseReport);
            $output = $sanitized?->render() ?? '';
        } catch (ParsingCancelledException $e) {
            return $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_CANCELLED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                $parseReport,
                $documentId,
                $parseReport?->nodeCount ?? 0,
                0,
                $e->getMessage() ?: 'The HTML sanitization was cancelled.',
            ));
        } catch (ParsingException $e) {
            $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_SANITIZE_FAILED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                $e->getReport() ?? $parseReport,
                $e->getDocumentId() ?? $documentId,
                $e->getNodeCount(),
                0,
                $e->getMessage(),
            ));

            throw $e;
        } catch (\Throwable $e) {
            $this->publishReport($operation, $this->createReport(
                $reportElement,
                $context,
                SanitizationReport::STAGE_SANITIZE_FAILED,
                $originalInputDigest,
                $inputDigest,
                '',
                $truncated,
                false,
                $parseReport,
                $documentId,
                $parseReport?->nodeCount ?? 0,
                0,
                $e->getMessage(),
            ));

            throw $e;
        }

        $parseReport ??= $this->domVisitors[$context]->getLastReport();
        $nodeCount = $parseReport?->nodeCount
            ?? ($sanitized instanceof DocumentNode ? $sanitized->getSourceNodeCount() : $this->domVisitors[$context]->getLastSourceNodeCount());
        $outputNodeCount = $sanitized instanceof DocumentNode ? $sanitized->getOutputNodeCount() : 0;
        $stage = null !== $parseReport && ParseReport::STAGE_EMPTY === $parseReport->stage
            ? SanitizationReport::STAGE_EMPTY
            : SanitizationReport::STAGE_COMMITTED;

        return $this->publishReport($operation, $this->createReport(
            $reportElement,
            $context,
            $stage,
            $originalInputDigest,
            $inputDigest,
            $output,
            $truncated,
            true,
            $parseReport,
            $documentId,
            $nodeCount,
            $outputNodeCount,
        ));
    }

    public function getLastReport(): ?SanitizationReport
    {
        return $this->lastReport;
    }

    public function getLastParseReport(): ?ParseReport
    {
        if (null !== $this->lastReport) {
            return $this->lastReport->parseReport;
        }

        return $this->parser instanceof NativeParser ? $this->parser->getLastReport() : null;
    }

    public function getLastInputDigest(): ?string
    {
        return $this->lastReport?->inputDigest;
    }

    public function getLastDocumentId(): ?string
    {
        return $this->lastReport?->documentId;
    }

    public function getLastDocumentIdentity(): ?string
    {
        return $this->lastReport?->documentId;
    }

    public function getLastDocumentObjectId(): ?int
    {
        return $this->lastReport?->getDocumentObjectId();
    }

    public function getLastNodeCount(): int
    {
        return $this->lastReport?->nodeCount ?? 0;
    }

    /**
     * Cancels the active parse when the parser supports it.
     *
     * This is mostly useful when the sanitizer is shared between fibers: the
     * cancelled parse publishes a non-committed report and no partial HTML.
     */
    public function cancel(): void
    {
        if ($this->parser instanceof NativeParser) {
            $this->parser->cancel('The HTML parse was cancelled.');
        }
    }

    private function publishReport(int $operation, SanitizationReport $report): SanitizationReport
    {
        if ($operation === $this->operation) {
            $this->lastReport = $report;
        }

        return $report;
    }

    private function createReport(
        string $element,
        string $context,
        string $stage,
        string $originalInputDigest,
        string $inputDigest,
        string $output,
        bool $truncated,
        bool $committed,
        ?ParseReport $parseReport,
        ?string $documentId,
        int $nodeCount,
        int $outputNodeCount,
        ?string $reason = null,
    ): SanitizationReport {
        return new SanitizationReport(
            $element,
            $context,
            $stage,
            $originalInputDigest,
            $inputDigest,
            $output,
            hash('sha256', $output),
            $truncated,
            $committed,
            $parseReport,
            $documentId,
            $nodeCount,
            $outputNodeCount,
            $reason,
        );
    }

    private function documentWasReused(\Dom\Document|\DOMDocument $document, string $inputDigest, \Dom\Node|\DOMNode|null $node = null): bool
    {
        $objectId = \spl_object_id($document);

        if (isset(self::$documentDigests[$objectId])) {
            [$knownDocument, $knownDigest, $knownFingerprint] = self::$documentDigests[$objectId];

            if ($knownDocument === $document) {
                if (null === $knownDigest || $knownDigest !== $inputDigest) {
                    // Detected a stale document: never let it become valid for
                    // another input again, even if the old digest is retried.
                    self::$documentDigests[$objectId] = [$document, null, null];

                    return true;
                }

                if (null === $node) {
                    return false;
                }

                $fingerprint = DomFingerprint::of($node);

                if (null !== $knownFingerprint && $knownFingerprint !== $fingerprint) {
                    self::$documentDigests[$objectId] = [$document, null, null];

                    return true;
                }

                if (null === $knownFingerprint) {
                    self::$documentDigests[$objectId] = [$document, $knownDigest, $fingerprint];
                }

                return false;
            }
        }

        self::$documentDigests[$objectId] = [$document, $inputDigest, null === $node ? null : DomFingerprint::of($node)];

        if (16 < \count(self::$documentDigests)) {
            $oldest = array_key_first(self::$documentDigests);
            unset(self::$documentDigests[$oldest]);
        }

        return false;
    }

    private static function documentIdentity(\Dom\Document|\DOMDocument $document): string
    {
        return \sprintf('%s:%d', $document::class, \spl_object_id($document));
    }

    /**
     * Returns true when the truncation point falls inside an HTML tag or a
     * quoted attribute value, where the HTML parser would otherwise synthesize
     * a partial node from the cut input.
     */
    private function truncationBreaksMarkup(string $input): bool
    {
        $insideTag = false;
        $quote = null;
        $length = \strlen($input);

        for ($i = 0; $i < $length; ++$i) {
            $char = $input[$i];

            if (!$insideTag) {
                if ('<' === $char && isset($input[$i + 1])) {
                    $next = $input[$i + 1];

                    if (('a' <= $next && 'z' >= $next) || ('A' <= $next && 'Z' >= $next) || '/' === $next || '!' === $next || '?' === $next) {
                        $insideTag = true;
                    }
                }

                continue;
            }

            if (null !== $quote) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ('"' === $char || "'" === $char) {
                $quote = $char;
            } elseif ('>' === $char) {
                $insideTag = false;
            }
        }

        return $insideTag || null !== $quote;
    }

    private function isValidUtf8(string $html): bool
    {
        // preg_match() fails silently on strings containing invalid UTF-8.
        return '' === $html || preg_match('//u', $html);
    }

    private function createDomVisitorForContext(string $context): DomVisitor
    {
        $elementsConfig = [];

        // Head: only a few elements are allowed
        if (W3CReference::CONTEXT_HEAD === $context) {
            foreach ($this->config->getAllowedElements() as $allowedElement => $allowedAttributes) {
                if (\array_key_exists($allowedElement, W3CReference::HEAD_ELEMENTS)) {
                    $elementsConfig[$allowedElement] = $allowedAttributes;
                }
            }

            foreach ($this->config->getBlockedElements() as $blockedElement => $v) {
                if (\array_key_exists($blockedElement, W3CReference::HEAD_ELEMENTS)) {
                    $elementsConfig[$blockedElement] = HtmlSanitizerAction::Block;
                }
            }

            foreach ($this->config->getDroppedElements() as $droppedElement => $v) {
                if (\array_key_exists($droppedElement, W3CReference::HEAD_ELEMENTS)) {
                    $elementsConfig[$droppedElement] = HtmlSanitizerAction::Drop;
                }
            }

            return new DomVisitor($this->config, $elementsConfig);
        }

        // Body: allow any configured element that isn't in <head>
        foreach ($this->config->getAllowedElements() as $allowedElement => $allowedAttributes) {
            if (!\array_key_exists($allowedElement, W3CReference::HEAD_ELEMENTS)) {
                $elementsConfig[$allowedElement] = $allowedAttributes;
            }
        }

        foreach ($this->config->getBlockedElements() as $blockedElement => $v) {
            if (!\array_key_exists($blockedElement, W3CReference::HEAD_ELEMENTS)) {
                $elementsConfig[$blockedElement] = HtmlSanitizerAction::Block;
            }
        }

        foreach ($this->config->getDroppedElements() as $droppedElement => $v) {
            if (!\array_key_exists($droppedElement, W3CReference::HEAD_ELEMENTS)) {
                $elementsConfig[$droppedElement] = HtmlSanitizerAction::Drop;
            }
        }

        return new DomVisitor($this->config, $elementsConfig);
    }
}
