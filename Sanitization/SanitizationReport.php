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

use Symfony\Component\HtmlSanitizer\Exception\InvalidSanitizationReportException;
use Symfony\Component\HtmlSanitizer\Exception\SanitizationException;

/**
 * Immutable, self-validating result of one sanitization transaction.
 *
 * The output string and the node evidence are created together and covered by
 * the same generation hash. Persisting {@see self::toArray()} and restoring it
 * with {@see self::fromArray()} preserves that generation; a modified or
 * incomplete persisted report is rejected instead of being exposed as output.
 */
final class SanitizationReport implements \JsonSerializable
{
    public const FORMAT_VERSION = 1;
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';

    /**
     * @param list<SanitizationNodeEvidence>  $nodes
     * @param list<SanitizationModification>  $modifications
     * @param array<string, mixed>            $metadata
     */
    private function __construct(
        private string $status,
        private string $output,
        private string $generation,
        private string $policyGeneration,
        private string $context,
        private string $parserContext,
        private string $inputHash,
        private string $outputHash,
        private int $inputLength,
        private int $processedLength,
        private array $nodes,
        private array $modifications,
        private ?SanitizationFailure $failure,
        private array $metadata,
        private ?\Throwable $previousError = null,
    ) {
    }

    /**
     * @param list<SanitizationNodeEvidence> $nodes
     * @param list<SanitizationModification> $modifications
     * @param array<string, mixed>           $metadata
     */
    public static function complete(
        string $output,
        string $context,
        string $parserContext,
        string $policyGeneration,
        string $inputHash,
        int $inputLength,
        int $processedLength,
        array $nodes,
        array $modifications,
        array $metadata = [],
        ?\Throwable $previousError = null,
    ): self {
        $outputHash = hash('sha256', $output);
        $generation = self::computeGeneration([
            'version' => self::FORMAT_VERSION,
            'status' => self::STATUS_COMPLETE,
            'context' => $context,
            'parser_context' => $parserContext,
            'policy_generation' => $policyGeneration,
            'input_hash' => $inputHash,
            'input_length' => $inputLength,
            'processed_length' => $processedLength,
            'output_hash' => $outputHash,
            'nodes' => array_map(
                static fn (SanitizationNodeEvidence $node): array => $node->toArray(),
                $nodes,
            ),
            'modifications' => array_map(
                static fn (SanitizationModification $modification): array => $modification->toArray(),
                $modifications,
            ),
            'metadata' => $metadata,
        ]);

        $report = new self(
            self::STATUS_COMPLETE,
            $output,
            $generation,
            $policyGeneration,
            $context,
            $parserContext,
            $inputHash,
            $outputHash,
            $inputLength,
            $processedLength,
            $nodes,
            $modifications,
            null,
            $metadata,
            $previousError,
        );
        $report->validate();

        return $report;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failed(
        string $context,
        string $parserContext,
        string $policyGeneration,
        string $inputHash,
        int $inputLength,
        int $processedLength,
        SanitizationFailure $failure,
        array $metadata = [],
        ?\Throwable $previousError = null,
    ): self {
        $generation = self::computeGeneration([
            'version' => self::FORMAT_VERSION,
            'status' => self::STATUS_FAILED,
            'context' => $context,
            'parser_context' => $parserContext,
            'policy_generation' => $policyGeneration,
            'input_hash' => $inputHash,
            'input_length' => $inputLength,
            'processed_length' => $processedLength,
            'failure' => $failure->toArray(),
            'metadata' => $metadata,
        ]);

        $report = new self(
            self::STATUS_FAILED,
            '',
            $generation,
            $policyGeneration,
            $context,
            $parserContext,
            $inputHash,
            hash('sha256', ''),
            $inputLength,
            $processedLength,
            [],
            [],
            $failure,
            $metadata,
            $previousError,
        );
        $report->validate();

        return $report;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isComplete(): bool
    {
        return self::STATUS_COMPLETE === $this->status;
    }

    public function isSuccessful(): bool
    {
        return $this->isComplete();
    }

    public function hasFailure(): bool
    {
        return null !== $this->failure;
    }

    public function isFailed(): bool
    {
        return !$this->isComplete();
    }

    public function isCancelled(): bool
    {
        return SanitizationFailure::STAGE_CANCELLED === $this->failure?->getStage();
    }

    public function getFailure(): ?SanitizationFailure
    {
        return $this->failure;
    }

    /**
     * Process-local original exception, when any. This is intentionally not
     * serialized: the persisted report keeps the stage, path and exception
     * class instead.
     */
    public function getPreviousError(): ?\Throwable
    {
        return $this->previousError;
    }

    public function getFailureMessage(): ?string
    {
        return $this->failure?->getFailureMessage();
    }

    public function getFailureReport(): ?string
    {
        return $this->getFailureMessage();
    }

    public function getNodePath(): ?string
    {
        return $this->failure?->getNodePath();
    }

    public function getFailureLocation(): ?string
    {
        return $this->failure?->getLocation();
    }

    public function getFailureStage(): ?string
    {
        return $this->failure?->getStage();
    }

    public function getFailurePolicy(): ?string
    {
        return $this->failure?->getPolicy();
    }

    public function getFailureSanitizer(): ?string
    {
        return $this->failure?->getSanitizer();
    }

    /**
     * Returns the committed output, or an empty string when the transaction
     * failed. Consumers that must not accept an empty placeholder on failure
     * should use {@see self::getCommittedOutput()}.
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    public function getHtml(): string
    {
        return $this->output;
    }

    public function getString(): string
    {
        return $this->output;
    }

    public function getOutputString(): string
    {
        return $this->output;
    }

    public function getSanitizedOutput(): string
    {
        return $this->output;
    }

    public function getSanitizedHtml(): string
    {
        return $this->output;
    }

    public function getOutputOrNull(): ?string
    {
        return $this->isComplete() ? $this->output : null;
    }

    /**
     * Returns the output only for a committed complete transaction.
     *
     * @throws SanitizationException when the transaction failed
     */
    public function getCommittedOutput(): string
    {
        if (!$this->isComplete()) {
            throw new SanitizationException($this);
        }

        return $this->output;
    }

    public function getGeneration(): string
    {
        return $this->generation;
    }

    public function getTransactionId(): string
    {
        return $this->generation;
    }

    public function getPolicyGeneration(): string
    {
        return $this->policyGeneration;
    }

    public function getPolicyHash(): string
    {
        return $this->policyGeneration;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function getParserContext(): string
    {
        return $this->parserContext;
    }

    public function getInputHash(): string
    {
        return $this->inputHash;
    }

    public function getOutputHash(): string
    {
        return $this->outputHash;
    }

    public function getInputLength(): int
    {
        return $this->inputLength;
    }

    public function getProcessedLength(): int
    {
        return $this->processedLength;
    }

    public function isTruncated(): bool
    {
        return (bool) ($this->metadata['truncated'] ?? false);
    }

    /**
     * @return list<SanitizationNodeEvidence>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<SanitizationNodeEvidence>
     */
    public function getNodeEvidence(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<SanitizationNodeEvidence>
     */
    public function getEvidence(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<SanitizationNodeEvidence>
     */
    public function getOutputNodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<SanitizationNodeEvidence>
     */
    public function getElementNodes(): array
    {
        return array_values(array_filter(
            $this->nodes,
            static fn (SanitizationNodeEvidence $node): bool => 'element' === $node->getType(),
        ));
    }

    /**
     * @return list<SanitizationNodeEvidence>
     */
    public function getTextNodes(): array
    {
        return array_values(array_filter(
            $this->nodes,
            static fn (SanitizationNodeEvidence $node): bool => 'text' === $node->getType(),
        ));
    }

    public function getNodeAtOutputIndex(int $index): ?SanitizationNodeEvidence
    {
        return $this->nodes[$index] ?? null;
    }

    public function getNodeByInputPath(string $path): ?SanitizationNodeEvidence
    {
        foreach ($this->nodes as $node) {
            if ($path === $node->getInputPath()) {
                return $node;
            }
        }

        return null;
    }

    public function getNodeByOutputPath(string $path): ?SanitizationNodeEvidence
    {
        foreach ($this->nodes as $node) {
            if ($path === $node->getOutputPath()) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return list<SanitizationModification>
     */
    public function getModifications(): array
    {
        return $this->modifications;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getSummary(): string
    {
        if (null !== $this->failure) {
            return \sprintf(
                '%s report %s (%s)',
                $this->status,
                $this->generation,
                $this->failure->getFailureMessage(),
            );
        }

        return \sprintf(
            '%s report %s for policy %s with %d output nodes',
            $this->status,
            $this->generation,
            $this->policyGeneration,
            \count($this->nodes),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'status' => $this->status,
            'generation' => $this->generation,
            'policy_generation' => $this->policyGeneration,
            'context' => $this->context,
            'parser_context' => $this->parserContext,
            'input_hash' => $this->inputHash,
            'output_hash' => $this->outputHash,
            'input_length' => $this->inputLength,
            'processed_length' => $this->processedLength,
            'output' => $this->output,
            'nodes' => array_map(
                static fn (SanitizationNodeEvidence $node): array => $node->toArray(),
                $this->nodes,
            ),
            'modifications' => array_map(
                static fn (SanitizationModification $modification): array => $modification->toArray(),
                $this->modifications,
            ),
            'failure' => $this->failure?->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = \JSON_THROW_ON_ERROR): string
    {
        return json_encode($this->toArray(), $flags | \JSON_THROW_ON_ERROR);
    }

    /**
     * @throws InvalidSanitizationReportException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidSanitizationReportException('Sanitization report is not valid JSON.', 0, $e);
        }

        if (!\is_array($data)) {
            throw new InvalidSanitizationReportException('Sanitization report JSON must contain an object.');
        }

        return self::fromArray($data);
    }

    /**
     * Restores and validates a persisted report. Any modification, missing
     * node or incomplete output invalidates the generation and is rejected.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidSanitizationReportException
     */
    public static function fromArray(array $data): self
    {
        if (self::FORMAT_VERSION !== ($data['version'] ?? null)) {
            throw new InvalidSanitizationReportException('Unsupported sanitization report format.');
        }

        $status = $data['status'] ?? null;
        if (!\in_array($status, [self::STATUS_COMPLETE, self::STATUS_FAILED], true)) {
            throw new InvalidSanitizationReportException('Invalid sanitization report status.');
        }

        if (!\is_string($data['output'] ?? null)
            || !\is_string($data['generation'] ?? null)
            || !\is_string($data['policy_generation'] ?? null)
            || !\is_string($data['context'] ?? null)
            || !\is_string($data['parser_context'] ?? null)
            || !\is_string($data['input_hash'] ?? null)
            || !\is_string($data['output_hash'] ?? null)
            || !\is_int($data['input_length'] ?? null)
            || !\is_int($data['processed_length'] ?? null)
            || !\is_array($data['nodes'] ?? null)
            || !\is_array($data['modifications'] ?? null)
        ) {
            throw new InvalidSanitizationReportException('Sanitization report is missing committed fields.');
        }

        $nodes = [];
        foreach ($data['nodes'] as $node) {
            if (!\is_array($node)) {
                throw new InvalidSanitizationReportException('Invalid sanitization node evidence.');
            }
            $nodes[] = SanitizationNodeEvidence::fromArray($node);
        }

        $modifications = [];
        foreach ($data['modifications'] as $modification) {
            if (!\is_array($modification)) {
                throw new InvalidSanitizationReportException('Invalid sanitization modification evidence.');
            }
            $modifications[] = SanitizationModification::fromArray($modification);
        }

        $failure = null;
        if (self::STATUS_FAILED === $status) {
            if (!\is_array($data['failure'] ?? null)) {
                throw new InvalidSanitizationReportException('Failed sanitization report has no failure evidence.');
            }
            $failure = SanitizationFailure::fromArray($data['failure']);
        } elseif (null !== ($data['failure'] ?? null)) {
            throw new InvalidSanitizationReportException('Complete sanitization report cannot carry a failure.');
        }

        $metadata = \is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        $report = new self(
            $status,
            $data['output'],
            $data['generation'],
            $data['policy_generation'],
            $data['context'],
            $data['parser_context'],
            $data['input_hash'],
            $data['output_hash'],
            $data['input_length'],
            $data['processed_length'],
            $nodes,
            $modifications,
            $failure,
            $metadata,
        );
        $report->validate();

        return $report;
    }

    public function __toString(): string
    {
        return $this->getCommittedOutput();
    }

    /**
     * @return array{version: int, report: array<string, mixed>}
     */
    public function __serialize(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'report' => $this->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $report = isset($data['report']) && \is_array($data['report'])
            ? self::fromArray($data['report'])
            : self::fromArray($data);

        $this->status = $report->status;
        $this->output = $report->output;
        $this->generation = $report->generation;
        $this->policyGeneration = $report->policyGeneration;
        $this->context = $report->context;
        $this->parserContext = $report->parserContext;
        $this->inputHash = $report->inputHash;
        $this->outputHash = $report->outputHash;
        $this->inputLength = $report->inputLength;
        $this->processedLength = $report->processedLength;
        $this->nodes = $report->nodes;
        $this->modifications = $report->modifications;
        $this->failure = $report->failure;
        $this->metadata = $report->metadata;
        $this->previousError = null;
    }

    /**
     * @throws InvalidSanitizationReportException
     */
    public function validate(): void
    {
        if (!$this->isComplete() && null === $this->failure) {
            throw new InvalidSanitizationReportException('Failed sanitization report has no failure evidence.');
        }

        if ($this->isComplete() && null !== $this->failure) {
            throw new InvalidSanitizationReportException('Complete sanitization report cannot carry a failure.');
        }

        if (!$this->isComplete() && '' !== $this->output) {
            throw new InvalidSanitizationReportException('Failed sanitization report cannot publish output.');
        }

        if ($this->outputHash !== hash('sha256', $this->output)) {
            throw new InvalidSanitizationReportException('Sanitization output hash does not match the report.');
        }

        $expectedIndex = 0;
        $outputPaths = [];
        foreach ($this->nodes as $node) {
            if (!$node instanceof SanitizationNodeEvidence) {
                throw new InvalidSanitizationReportException('Sanitization report contains an invalid node.');
            }
            if ($node->getOutputIndex() !== $expectedIndex++) {
                throw new InvalidSanitizationReportException('Sanitization node output indexes are not contiguous.');
            }
            if ('' === $node->getOutputPath() || '' === $node->getInputPath() || '' === $node->getPolicy() || '' === $node->getAction()) {
                throw new InvalidSanitizationReportException('Sanitization node evidence is incomplete.');
            }
            if (isset($outputPaths[$node->getOutputPath()])) {
                throw new InvalidSanitizationReportException('Sanitization node output paths are not unique.');
            }
            $outputPaths[$node->getOutputPath()] = true;
        }

        foreach ($this->modifications as $modification) {
            if (!$modification instanceof SanitizationModification
                || '' === $modification->getInputPath()
                || '' === $modification->getPolicy()
                || '' === $modification->getAction()) {
                throw new InvalidSanitizationReportException('Sanitization modification evidence is incomplete.');
            }
        }

        $expectedGeneration = self::computeGeneration($this->generationPayload());
        if (!hash_equals($expectedGeneration, $this->generation)) {
            throw new InvalidSanitizationReportException('Sanitization generation does not match the report.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generationPayload(): array
    {
        if (!$this->isComplete()) {
            return [
                'version' => self::FORMAT_VERSION,
                'status' => $this->status,
                'context' => $this->context,
                'parser_context' => $this->parserContext,
                'policy_generation' => $this->policyGeneration,
                'input_hash' => $this->inputHash,
                'input_length' => $this->inputLength,
                'processed_length' => $this->processedLength,
                'failure' => $this->failure?->toArray(),
                'metadata' => $this->metadata,
            ];
        }

        return [
            'version' => self::FORMAT_VERSION,
            'status' => $this->status,
            'context' => $this->context,
            'parser_context' => $this->parserContext,
            'policy_generation' => $this->policyGeneration,
            'input_hash' => $this->inputHash,
            'input_length' => $this->inputLength,
            'processed_length' => $this->processedLength,
            'output_hash' => $this->outputHash,
            'nodes' => array_map(
                static fn (SanitizationNodeEvidence $node): array => $node->toArray(),
                $this->nodes,
            ),
            'modifications' => array_map(
                static fn (SanitizationModification $modification): array => $modification->toArray(),
                $this->modifications,
            ),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function computeGeneration(array $payload): string
    {
        return SanitizationFingerprint::hash($payload);
    }
}
