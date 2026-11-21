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

use Symfony\Component\HtmlSanitizer\Exception\SanitizationException;
use Symfony\Component\HtmlSanitizer\Parser\NativeParser;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;
use Symfony\Component\HtmlSanitizer\Reference\W3CReference;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationAborted;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationCancellation;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationFailure;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationFingerprint;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationReport;
use Symfony\Component\HtmlSanitizer\TextSanitizer\StringSanitizer;
use Symfony\Component\HtmlSanitizer\Visitor\DomVisitor;
use Symfony\Component\HtmlSanitizer\Visitor\SanitizationTrace;

/**
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
final class HtmlSanitizer implements HtmlSanitizerEvidenceInterface
{
    private ParserInterface $parser;

    /**
     * @var array<string, DomVisitor>
     */
    private array $domVisitors = [];

    public function __construct(
        private HtmlSanitizerConfig $config,
        ?ParserInterface $parser = null,
    ) {
        $this->parser = $parser ?? new NativeParser();
    }

    public function sanitize(string $input): string
    {
        return $this->committedOutput($this->runSanitizationTransaction(W3CReference::CONTEXT_BODY, $input, null, false));
    }

    public function sanitizeFor(string $element, string $input): string
    {
        return $this->committedOutput($this->runSanitizationTransaction($element, $input, null, false));
    }

    /**
     * Sanitizes a string and returns the output together with node-level
     * evidence produced by the same transaction.
     *
     * The string methods above are unchanged for callers. This method returns
     * a failed report instead of publishing a partial output when the input
     * cannot be sanitized completely.
     */
    public function sanitizeWithEvidence(string $input, ?SanitizationCancellation $cancellation = null): SanitizationReport
    {
        return $this->sanitizeForWithEvidence(W3CReference::CONTEXT_BODY, $input, $cancellation);
    }

    /**
     * Context-sensitive counterpart of {@see self::sanitizeWithEvidence()}.
     */
    public function sanitizeForWithEvidence(string $element, string $input, ?SanitizationCancellation $cancellation = null): SanitizationReport
    {
        return $this->runSanitizationTransaction($element, $input, $cancellation, true);
    }

    /**
     * Alias of {@see self::sanitizeWithEvidence()}.
     */
    public function sanitizeWithReport(string $input, ?SanitizationCancellation $cancellation = null): SanitizationReport
    {
        return $this->sanitizeWithEvidence($input, $cancellation);
    }

    /**
     * Alias of {@see self::sanitizeForWithEvidence()}.
     */
    public function sanitizeForWithReport(string $element, string $input, ?SanitizationCancellation $cancellation = null): SanitizationReport
    {
        return $this->sanitizeForWithEvidence($element, $input, $cancellation);
    }

    /**
     * Alias of {@see self::sanitizeWithEvidence()}.
     */
    public function sanitizeDetailed(string $input, ?SanitizationCancellation $cancellation = null): SanitizationReport
    {
        return $this->sanitizeWithEvidence($input, $cancellation);
    }

    /**
     * Alias of {@see self::sanitizeForWithEvidence()}.
     */
    public function sanitizeForDetailed(string $element, string $input, ?SanitizationCancellation $cancellation = null): SanitizationReport
    {
        return $this->sanitizeForWithEvidence($element, $input, $cancellation);
    }

    private function runSanitizationTransaction(string $element, string $input, ?SanitizationCancellation $cancellation, bool $collectEvidence): SanitizationReport
    {
        $element = StringSanitizer::htmlLower($element);
        $context = W3CReference::CONTEXTS_MAP[$element] ?? W3CReference::CONTEXT_BODY;
        $parserContext = isset(W3CReference::BODY_ELEMENTS[$element]) ? $element : $context;
        $inputHash = hash('sha256', $input);
        $inputLength = \strlen($input);
        $processedLength = $inputLength;
        $policyGeneration = SanitizationFingerprint::policy($this->config, $context, $parserContext);
        $trace = new SanitizationTrace($cancellation, $collectEvidence);
        $metadata = [
            'parser' => $this->parser::class,
            'context' => $context,
            'parser_context' => $parserContext,
        ];

        try {
            // Prevent DOS attack induced by extremely long HTML strings
            if (-1 !== ($maxLength = $this->config->getMaxInputLength()) && \strlen($input) > $maxLength) {
                // Don't cut a multibyte character, which would make the input invalid UTF-8
                while (0 < $maxLength && 0x80 === (\ord($input[$maxLength]) & 0xC0)) {
                    --$maxLength;
                }

                $input = substr($input, 0, $maxLength);
                $metadata['truncated'] = true;
                $metadata['original_input_length'] = $inputLength;
                $trace->recordModification('input', 'truncate', 'document', 'max-input-length', 'input', null, null, null, [
                    'original_length' => $inputLength,
                    'processed_length' => \strlen($input),
                ]);
            }

            $processedLength = \strlen($input);
            $metadata['processed_input_hash'] = hash('sha256', $input);
            $metadata['processed_input_length'] = $processedLength;
            $trace->checkCancellation('document', null);

            // Text context: early return with HTML encoding
            if (W3CReference::CONTEXT_TEXT === $context) {
                return $this->createCompleteReport(
                    $this->encodeTextEvidence($input, 'text-context', $trace),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $trace,
                    $metadata,
                );
            }

            // Only operate on valid UTF-8 strings. This is necessary to prevent cross
            // site scripting issues on Internet Explorer 6. Idea from Drupal (filter_xss).
            if (!$this->isValidUtf8($input)) {
                $metadata['invalid_utf8'] = true;
                $trace->recordModification('input', 'drop', 'document', 'utf8', metadata: ['reason' => 'invalid-utf8']);

                return $this->createCompleteReport(
                    '',
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $trace,
                    $metadata,
                );
            }

            // Remove NULL character and HTML entities for null byte
            $input = str_replace(\chr(0), '�', $input);
            $processedLength = \strlen($input);
            $metadata['processed_input_hash'] = hash('sha256', $input);
            $metadata['processed_input_length'] = $processedLength;

            if ('' === trim($input)) {
                $trace->recordModification('input', 'drop', 'document', 'empty-input', metadata: ['reason' => 'empty-input']);

                return $this->createCompleteReport(
                    '',
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $trace,
                    $metadata,
                );
            }

            // Plain text needs no parsing, only the normalization of line breaks that the parser would do
            if (W3CReference::CONTEXT_BODY === $parserContext && $this->parser instanceof NativeParser && !str_contains($input, '<') && !str_contains($input, '&')) {
                $output = StringSanitizer::encodeHtmlEntities(str_replace(["\r\n", "\r"], "\n", $input));

                return $this->createCompleteReport(
                    $this->encodeTextEvidence($input, 'text-fast-path', $trace, $output),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $trace,
                    $metadata,
                );
            }

            // Parse as HTML
            try {
                $parsed = $this->parser->parse($input, $parserContext);
            } catch (\Throwable $e) {
                return $this->createFailureReport(
                    new SanitizationFailure(
                        'parse',
                        \sprintf('Parser "%s" failed for context "%s": %s', $this->parser::class, $parserContext, $e->getMessage()),
                        $parserContext.'[1]',
                        exceptionClass: $e::class,
                        code: (int) $e->getCode(),
                    ),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $metadata,
                    $e,
                );
            }

            $trace->checkCancellation('document', null);

            if (!$parsed) {
                $trace->recordModification('input', 'drop', 'document', 'parse', metadata: ['reason' => 'empty-parse-result']);

                return $this->createCompleteReport(
                    '',
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $trace,
                    $metadata,
                );
            }

            if ($collectEvidence) {
                try {
                    $frozen = $parsed->cloneNode(true);
                    if (!$frozen instanceof \Dom\Node && !$frozen instanceof \DOMNode) {
                        throw new \RuntimeException('The parser did not return a cloneable DOM node.');
                    }
                } catch (\Throwable $e) {
                    return $this->createFailureReport(
                        new SanitizationFailure(
                            'freeze-input',
                            'Unable to freeze the parsed DOM before visiting it: '.$e->getMessage(),
                            $parserContext.'[1]',
                            exceptionClass: $e::class,
                            code: (int) $e->getCode(),
                        ),
                        $context,
                        $parserContext,
                        $policyGeneration,
                        $inputHash,
                        $inputLength,
                        $processedLength,
                        $metadata,
                        $e,
                    );
                }

                $metadata['frozen_input'] = true;
            } else {
                $frozen = $parsed;
            }

            try {
                // Other context: build a DOM visitor
                $visitorKey = $context.'|'.$policyGeneration;
                $this->domVisitors[$visitorKey] ??= $this->createDomVisitorForContext($context);
            } catch (\Throwable $e) {
                return $this->createFailureReport(
                    new SanitizationFailure(
                        'visitor',
                        'Unable to build the DOM visitor: '.$e->getMessage(),
                        $parserContext.'[1]',
                        exceptionClass: $e::class,
                        code: (int) $e->getCode(),
                    ),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $metadata,
                    $e,
                );
            }

            try {
                $sanitized = $this->domVisitors[$visitorKey]->visitWithTrace($frozen, $trace);
            } catch (SanitizationAborted $e) {
                return $this->createFailureReport(
                    $e->getFailure(),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $metadata,
                    $e->getPrevious(),
                );
            } catch (\Throwable $e) {
                return $this->createFailureReport(
                    new SanitizationFailure(
                        'visit',
                        $e->getMessage(),
                        $trace->getCurrentInputPath(),
                        element: $trace->getCurrentElement(),
                        exceptionClass: $e::class,
                        code: (int) $e->getCode(),
                    ),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $metadata,
                    $e,
                );
            }

            try {
                $trace->checkCancellation('document', null);
                $output = $sanitized?->render() ?? '';
                $trace->checkCancellation('document', null);
            } catch (SanitizationAborted $e) {
                return $this->createFailureReport(
                    $e->getFailure(),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $metadata,
                    $e->getPrevious(),
                );
            } catch (\Throwable $e) {
                return $this->createFailureReport(
                    new SanitizationFailure(
                        'render',
                        $e->getMessage(),
                        $trace->getCurrentInputPath() ?? $trace->getLastInputPath(),
                        element: $trace->getCurrentElement() ?? $trace->getLastElement(),
                        exceptionClass: $e::class,
                        code: (int) $e->getCode(),
                    ),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $metadata,
                    $e,
                );
            }

            try {
                return $this->createCompleteReport(
                    $output,
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $trace,
                    $metadata,
                );
            } catch (\Throwable $e) {
                return $this->createFailureReport(
                    new SanitizationFailure(
                        'commit',
                        'Unable to commit sanitized output and evidence atomically: '.$e->getMessage(),
                        $trace->getCurrentInputPath() ?? $trace->getLastInputPath(),
                        element: $trace->getCurrentElement() ?? $trace->getLastElement(),
                        exceptionClass: $e::class,
                        code: (int) $e->getCode(),
                    ),
                    $context,
                    $parserContext,
                    $policyGeneration,
                    $inputHash,
                    $inputLength,
                    $processedLength,
                    $metadata,
                    $e,
                );
            }
        } catch (SanitizationAborted $e) {
            return $this->createFailureReport(
                $e->getFailure(),
                $context,
                $parserContext,
                $policyGeneration,
                $inputHash,
                $inputLength,
                $processedLength,
                $metadata,
                $e->getPrevious(),
            );
        } catch (\Throwable $e) {
            return $this->createFailureReport(
                new SanitizationFailure(
                    'transaction',
                    $e->getMessage(),
                    $trace->getCurrentInputPath(),
                    element: $trace->getCurrentElement(),
                    exceptionClass: $e::class,
                    code: (int) $e->getCode(),
                ),
                $context,
                $parserContext,
                $policyGeneration,
                $inputHash,
                $inputLength,
                $processedLength,
                $metadata,
                $e,
            );
        }
    }

    private function encodeTextEvidence(string $input, string $policy, SanitizationTrace $trace, ?string $output = null): string
    {
        $output ??= StringSanitizer::encodeHtmlEntities($input);
        if (!$trace->collectsEvidence()) {
            return $output;
        }

        $action = $output === $input ? 'keep' : 'encode';
        $modification = $trace->recordModification('text', $action, '#text[1]', $policy, metadata: [
            'input_length' => \strlen($input),
            'output_length' => \strlen($output),
        ]);
        $trace->addNode(
            0,
            '#text[1]',
            '#text[1]',
            'text',
            null,
            'text',
            $action,
            [],
            [$modification],
        );

        return $output;
    }

    private function committedOutput(SanitizationReport $report): string
    {
        if ($report->isComplete()) {
            return $report->getOutput();
        }

        // Preserve the historical string API error semantics: the original
        // parser or sanitizer exception is rethrown unchanged. Callers that
        // need the path and stage use sanitizeWithEvidence().
        if (null !== $previous = $report->getPreviousError()) {
            throw $previous;
        }

        throw new SanitizationException($report);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function createCompleteReport(
        string $output,
        string $context,
        string $parserContext,
        string $policyGeneration,
        string $inputHash,
        int $inputLength,
        int $processedLength,
        SanitizationTrace $trace,
        array $metadata,
    ): SanitizationReport {
        return SanitizationReport::complete(
            $output,
            $context,
            $parserContext,
            $policyGeneration,
            $inputHash,
            $inputLength,
            $processedLength,
            $trace->getNodes(),
            $trace->getModifications(),
            $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function createFailureReport(
        SanitizationFailure $failure,
        string $context,
        string $parserContext,
        string $policyGeneration,
        string $inputHash,
        int $inputLength,
        int $processedLength,
        array $metadata,
        ?\Throwable $previous = null,
    ): SanitizationReport {
        try {
            return SanitizationReport::failed(
                $context,
                $parserContext,
                $policyGeneration,
                $inputHash,
                $inputLength,
                $processedLength,
                $failure,
                $metadata,
                $previous,
            );
        } catch (\Throwable $e) {
            // A failed report is the final safety boundary. If even its
            // serialization fails, expose a minimal report whose output is
            // still empty and whose failure points at the commit stage.
            return SanitizationReport::failed(
                $context,
                $parserContext,
                $policyGeneration,
                $inputHash,
                $inputLength,
                $processedLength,
                new SanitizationFailure(
                    'commit',
                    'Unable to persist the sanitization failure report: '.$e->getMessage(),
                    exceptionClass: $e::class,
                    code: (int) $e->getCode(),
                ),
                ['parser' => $this->parser::class],
                $e,
            );
        }
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
