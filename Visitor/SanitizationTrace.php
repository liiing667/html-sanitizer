<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Visitor;

use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationAborted;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationCancellation;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationFailure;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationModification;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationNodeEvidence;
use Symfony\Component\HtmlSanitizer\Visitor\Node\DocumentNode;
use Symfony\Component\HtmlSanitizer\Visitor\Node\NodeInterface;

/**
 * Per-visit mutable staging area.
 *
 * A trace is created for exactly one sanitization transaction and is never
 * stored on the HTML sanitizer or visitor. Aborting a visit therefore throws
 * away every node path and modification tied to that transaction.
 *
 * @internal
 */
final class SanitizationTrace
{
    private const MAX_PATH_LENGTH = 512;

    private static ?SanitizationModification $noopModification = null;

    /**
     * @var list<SanitizationNodeEvidence>
     */
    private array $nodes = [];

    /**
     * @var list<SanitizationModification>
     */
    private array $modifications = [];

    /**
     * @var array<string, list<SanitizationModification>>
     */
    private array $modificationsByPath = [];

    /**
     * @var array<int, string>
     */
    private array $outputPaths = [];

    /**
     * @var array<int, int|string>
     */
    private array $layoutKeys = [];

    /**
     * @var array<string, int>
     */
    private array $childCounters = [];

    private ?string $currentInputPath = null;
    private ?string $currentElement = null;
    private ?string $lastInputPath = null;
    private ?string $lastElement = null;

    public function __construct(
        private readonly ?SanitizationCancellation $cancellation = null,
        private readonly bool $collectEvidence = true,
    ) {
    }

    public function collectsEvidence(): bool
    {
        return $this->collectEvidence;
    }

    public function enterLocation(string $inputPath, ?string $element): void
    {
        $this->currentInputPath = $inputPath;
        $this->currentElement = $element;
        $this->lastInputPath = $inputPath;
        $this->lastElement = $element;
        $this->checkCancellation($inputPath, $element);
    }

    public function leaveLocation(): void
    {
        $this->currentInputPath = null;
        $this->currentElement = null;
    }

    public function getCurrentInputPath(): ?string
    {
        return $this->currentInputPath;
    }

    public function getCurrentElement(): ?string
    {
        return $this->currentElement;
    }

    public function getLastInputPath(): ?string
    {
        return $this->lastInputPath;
    }

    public function getLastElement(): ?string
    {
        return $this->lastElement;
    }

    public function checkCancellation(?string $inputPath = null, ?string $element = null): void
    {
        if (!$this->cancellation?->isCancelled()) {
            return;
        }

        throw new SanitizationAborted(new SanitizationFailure(
            'cancelled',
            $this->cancellation->getReason(),
            $inputPath ?? $this->currentInputPath,
            element: $element ?? $this->currentElement,
            policy: 'cancelled',
        ));
    }

    /**
     * @param array<string, string|null> $attributes
     */
    public function addNode(
        int $outputIndex,
        string $outputPath,
        string $inputPath,
        string $type,
        ?string $name,
        string $policy,
        string $action,
        array $attributes,
        array $modifications,
    ): void {
        if (!$this->collectEvidence) {
            return;
        }

        $this->nodes[] = new SanitizationNodeEvidence(
            $outputIndex,
            $outputPath,
            $inputPath,
            $type,
            $name,
            $policy,
            $action,
            $attributes,
            $modifications,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordModification(
        string $kind,
        string $action,
        string $inputPath,
        string $policy,
        ?string $name = null,
        ?string $before = null,
        ?string $after = null,
        ?string $sanitizer = null,
        array $metadata = [],
    ): SanitizationModification {
        if (!$this->collectEvidence) {
            return self::$noopModification ??= new SanitizationModification('noop', 'keep', $inputPath ?: 'document', 'none');
        }

        $modification = new SanitizationModification(
            $kind,
            $action,
            $inputPath,
            $policy,
            $name,
            $before,
            $after,
            $sanitizer,
            $metadata,
        );

        $this->modifications[] = $modification;
        $this->modificationsByPath[$inputPath][] = $modification;

        return $modification;
    }

    /**
     * @return list<SanitizationModification>
     */
    public function getModificationsForPath(string $inputPath): array
    {
        if (!$this->collectEvidence) {
            return [];
        }

        return $this->modificationsByPath[$inputPath] ?? [];
    }

    /**
     * Computes the output path under the nearest rendered parent.
     *
     * Blocked nodes keep their children but do not render a tag, so their
     * output path is inherited from the closest rendered ancestor.
     */
    public function outputPathFor(NodeInterface $parent, string $name): string
    {
        if (!$this->collectEvidence) {
            return '';
        }

        $layoutKey = $this->layoutKey($parent);
        $parentPath = $this->outputPaths[\spl_object_id($parent)] ?? '';
        $counterKey = $layoutKey.'|'.$name;
        $index = $this->childCounters[$counterKey] = ($this->childCounters[$counterKey] ?? 0) + 1;
        $segment = $name.'['.$index.']';

        return self::boundPath('' === $parentPath ? $segment : $parentPath.' > '.$segment);
    }

    public function registerOutputPath(NodeInterface $node, string $path): void
    {
        if (!$this->collectEvidence) {
            return;
        }

        $id = \spl_object_id($node);
        $this->outputPaths[$id] = $path;
        $this->layoutKeys[$id] = $id;
    }

    public function inheritOutputPath(NodeInterface $node, NodeInterface $parent): void
    {
        if (!$this->collectEvidence) {
            return;
        }

        $id = \spl_object_id($node);
        $this->outputPaths[$id] = $this->outputPaths[\spl_object_id($parent)] ?? '';
        $this->layoutKeys[$id] = $this->layoutKey($parent);
    }

    public function getOutputPath(NodeInterface $node): ?string
    {
        return $this->outputPaths[\spl_object_id($node)] ?? null;
    }

    /**
     * @return list<SanitizationNodeEvidence>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<SanitizationModification>
     */
    public function getModifications(): array
    {
        return $this->modifications;
    }

    /**
     * Keeps path evidence bounded for deeply nested documents. The prefix is a
     * stable hash of the full path, while the suffix keeps the most useful
     * source location readable.
     */
    public static function boundPath(string $path): string
    {
        if (\strlen($path) <= self::MAX_PATH_LENGTH) {
            return $path;
        }

        $suffixLength = self::MAX_PATH_LENGTH - 32;

        return '#'.substr(hash('sha256', $path), 0, 12).' … > '.substr($path, -$suffixLength);
    }

    private function layoutKey(NodeInterface $node): int|string
    {
        if ($node instanceof DocumentNode) {
            return 'document';
        }

        $id = \spl_object_id($node);

        return $this->layoutKeys[$id] ?? $id;
    }
}
