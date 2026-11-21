<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Visitor\Node;

use Symfony\Component\HtmlSanitizer\Parser\ParseReport;

/**
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
final class DocumentNode implements NodeInterface
{
    private array $children = [];
    private bool $committed = false;
    private int $sourceNodeCount = 0;
    private int $outputNodeCount = 0;

    /**
     * @param ParseReport|null $parseReport The parser evidence this document was built from
     * @param string|null      $documentId  The frozen DOM document identity
     * @param int|null         $generation  The parser generation that produced the DOM
     * @param string|null      $visitorId   The identity of the visit that accepted the DOM
     */
    public function __construct(
        private readonly ?ParseReport $parseReport = null,
        private readonly ?string $documentId = null,
        private readonly ?int $generation = null,
        private readonly ?string $visitorId = null,
    ) {
    }

    public function addChild(NodeInterface $node): void
    {
        $this->children[] = $node;
    }

    public function getParent(): ?NodeInterface
    {
        return null;
    }

    /**
     * Returns the identity of the visit that accepted the source DOM.
     *
     * This identity is part of the document evidence and must not change once
     * the document has been committed.
     */
    public function getVisitor(): ?string
    {
        return $this->visitorId;
    }

    public function getVisitorId(): ?string
    {
        return $this->visitorId;
    }

    public function getParseReport(): ?ParseReport
    {
        return $this->parseReport;
    }

    public function getDocumentId(): ?string
    {
        return $this->documentId ?? $this->parseReport?->documentId;
    }

    public function getDocumentIdentity(): ?string
    {
        return $this->getDocumentId();
    }

    public function getGeneration(): ?int
    {
        return $this->generation ?? $this->parseReport?->generation;
    }

    public function getInputDigest(): ?string
    {
        return $this->parseReport?->inputDigest;
    }

    public function getNodeCount(): int
    {
        return $this->parseReport?->nodeCount ?? 0;
    }

    public function setSourceNodeCount(int $sourceNodeCount): void
    {
        $this->sourceNodeCount = max(0, $sourceNodeCount);
    }

    public function getSourceNodeCount(): int
    {
        return $this->sourceNodeCount;
    }

    public function setOutputNodeCount(int $outputNodeCount): void
    {
        $this->outputNodeCount = max(0, $outputNodeCount);
    }

    public function getOutputNodeCount(): int
    {
        return $this->outputNodeCount;
    }

    public function commit(): void
    {
        if (null !== $this->parseReport && !$this->parseReport->isCommitted()) {
            throw new \LogicException('Cannot commit an HTML document whose parse transaction was not committed.');
        }

        $this->committed = true;
    }

    public function isCommitted(): bool
    {
        return $this->committed;
    }

    public function render(): string
    {
        // A document created by a visitor carries an identity and can only be
        // rendered after the complete visit has committed. Documents created
        // directly without a transaction keep their historical behaviour.
        if (null !== $this->parseReport || null !== $this->documentId || null !== $this->generation) {
            if (!$this->committed) {
                throw new \LogicException('Cannot render an HTML document before its visit is committed.');
            }

            if (null !== $this->parseReport && !$this->parseReport->isCommitted()) {
                throw new \LogicException('Cannot render an HTML document whose parse transaction was not committed.');
            }
        }

        $rendered = '';
        foreach ($this->children as $child) {
            $rendered .= $child->render();
        }

        return $rendered;
    }
}
