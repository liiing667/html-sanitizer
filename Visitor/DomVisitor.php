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

use Symfony\Component\HtmlSanitizer\Exception\ParsingException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Parser\ParseReport;
use Symfony\Component\HtmlSanitizer\TextSanitizer\StringSanitizer;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;
use Symfony\Component\HtmlSanitizer\Visitor\Model\Cursor;
use Symfony\Component\HtmlSanitizer\Visitor\Node\BlockedNode;
use Symfony\Component\HtmlSanitizer\Visitor\Node\DocumentNode;
use Symfony\Component\HtmlSanitizer\Visitor\Node\Node;
use Symfony\Component\HtmlSanitizer\Visitor\Node\NodeInterface;
use Symfony\Component\HtmlSanitizer\Visitor\Node\TextNode;

/**
 * Iterates over the parsed DOM tree to build the sanitized tree.
 *
 * The DomVisitor iterates over the parsed DOM tree, visits its nodes and build
 * a sanitized tree with their attributes and content.
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 *
 * @internal
 */
final class DomVisitor
{
    private HtmlSanitizerAction $defaultAction = HtmlSanitizerAction::Drop;
    private string $visitorInstanceId;
    private int $visitGeneration = 0;
    private ?ParseReport $lastReport = null;
    private ?string $lastVisitorId = null;
    private int $lastSourceNodeCount = 0;

    /**
     * Registry of attributes to forcefully set on nodes, index by element and attribute.
     *
     * @var array<string, array<string, string>>
     */
    private array $forcedAttributes;

    /**
     * Registry of attributes sanitizers indexed by element name and attribute name for
     * faster sanitization.
     *
     * @var array<string, array<string, list<AttributeSanitizerInterface>>>
     */
    private array $attributeSanitizers = [];

    /**
     * @param array<string, HtmlSanitizerAction|array<string, bool>> $elementsConfig Registry of allowed/blocked elements:
     *                                                                               * If an element is present as a key and contains an array, the element should be allowed
     *                                                                               and the array is the list of allowed attributes.
     *                                                                               * If an element is present as a key and contains an HtmlSanitizerAction, that action applies.
     *                                                                               * If an element is not present as a key, the default action applies.
     */
    public function __construct(
        private HtmlSanitizerConfig $config,
        private array $elementsConfig,
    ) {
        $this->visitorInstanceId = bin2hex(random_bytes(8));
        $this->forcedAttributes = $config->getForcedAttributes();

        foreach ($config->getAttributeSanitizers() as $attributeSanitizer) {
            foreach ($attributeSanitizer->getSupportedElements() ?? ['*'] as $element) {
                foreach ($attributeSanitizer->getSupportedAttributes() ?? ['*'] as $attribute) {
                    $this->attributeSanitizers[$element][$attribute][] = $attributeSanitizer;
                }
            }
        }

        $this->defaultAction = $config->getDefaultAction();
    }

    public function visit(\Dom\Node|\DOMNode $domNode, ?ParseReport $parseReport = null): ?NodeInterface
    {
        return $this->acceptNode($domNode, $parseReport);
    }

    /**
     * Accepts one parsed DOM tree as a complete document transaction.
     *
     * The visit freezes the source document, walks the tree at most once,
     * rejects nodes that belong to another document or appear more than once,
     * and commits the sanitized document only after the entire tree has been
     * processed.
     */
    public function acceptNode(\Dom\Node|\DOMNode $domNode, ?ParseReport $parseReport = null): ?NodeInterface
    {
        $visitorId = \sprintf('%s:%d', $parseReport?->parserId ?? $this->visitorInstanceId, ++$this->visitGeneration);
        $document = $domNode instanceof \Dom\Document || $domNode instanceof \DOMDocument
            ? $domNode
            : $domNode->ownerDocument;

        if (null === $document) {
            throw new ParsingException(ParsingException::STAGE_VISIT, 'The parsed node has no owner document.', $parseReport);
        }

        if (null !== $parseReport?->documentObjectId && $parseReport->documentObjectId !== \spl_object_id($document)) {
            throw new ParsingException(ParsingException::STAGE_VISIT, 'The parsed node does not belong to the document frozen by the parser.', $parseReport);
        }

        if (null !== $parseReport && !$parseReport->isCommitted()) {
            throw new ParsingException(ParsingException::STAGE_VISIT, 'The parsed document identity was not committed.', $parseReport);
        }

        $sourceNodeCount = self::countNodes($domNode);

        if (null !== $parseReport && 0 < $parseReport->nodeCount && $parseReport->nodeCount !== $sourceNodeCount) {
            throw new ParsingException(ParsingException::STAGE_VISIT, \sprintf('The parsed DOM changed before sanitization: expected %d nodes, found %d.', $parseReport->nodeCount, $sourceNodeCount), $parseReport);
        }

        $documentNode = new DocumentNode($parseReport, $parseReport?->documentId, $parseReport?->generation, $visitorId);
        $cursor = new Cursor($documentNode);
        $visited = new \SplObjectStorage();
        $outputNodeCount = 0;

        $this->visitChildren($domNode, $cursor, $document, $visited, $outputNodeCount, $parseReport);

        if (self::countNodes($domNode) !== $sourceNodeCount) {
            throw new ParsingException(ParsingException::STAGE_VISIT, 'The parsed DOM changed while it was being visited.', $parseReport);
        }

        $documentNode->setSourceNodeCount($sourceNodeCount);
        $documentNode->setOutputNodeCount($outputNodeCount);
        $documentNode->commit();

        if ($visitorId === \sprintf('%s:%d', $parseReport?->parserId ?? $this->visitorInstanceId, $this->visitGeneration)) {
            $this->lastReport = $parseReport;
            $this->lastVisitorId = $visitorId;
            $this->lastSourceNodeCount = $sourceNodeCount;
        }

        return $cursor->node;
    }

    public function getLastReport(): ?ParseReport
    {
        return $this->lastReport;
    }

    public function getLastVisitorId(): ?string
    {
        return $this->lastVisitorId;
    }

    public function getLastSourceNodeCount(): int
    {
        return $this->lastSourceNodeCount;
    }

    private function visitNode(\Dom\Node|\DOMNode $domNode, Cursor $cursor, \Dom\Document|\DOMDocument $document, \SplObjectStorage $visited, int &$outputNodeCount, ?ParseReport $parseReport): void
    {
        $nodeName = StringSanitizer::htmlLower($domNode->nodeName);

        // Visit recursively if the node was not dropped
        if ($this->enterNode($nodeName, $domNode, $cursor, $outputNodeCount)) {
            $this->visitChildren($domNode, $cursor, $document, $visited, $outputNodeCount, $parseReport);
            $cursor->node = $cursor->node->getParent();
        }
    }

    private function enterNode(string $domNodeName, \Dom\Node|\DOMNode $domNode, Cursor $cursor, int &$outputNodeCount): bool
    {
        if (!\array_key_exists($domNodeName, $this->elementsConfig)) {
            $action = $this->defaultAction;
            $allowedAttributes = [];
        } else {
            if (\is_array($this->elementsConfig[$domNodeName])) {
                $action = HtmlSanitizerAction::Allow;
                $allowedAttributes = $this->elementsConfig[$domNodeName];
            } else {
                $action = $this->elementsConfig[$domNodeName];
                $allowedAttributes = [];
            }
        }

        if (HtmlSanitizerAction::Drop === $action) {
            return false;
        }

        // Element should be blocked, retaining its children
        if (HtmlSanitizerAction::Block === $action) {
            $node = new BlockedNode($cursor->node);

            $cursor->node->addChild($node);
            $cursor->node = $node;

            return true;
        }

        // Otherwise create the node
        $node = new Node($cursor->node, $domNodeName);
        $this->setAttributes($domNodeName, $domNode, $node, $allowedAttributes);

        // Force configured attributes
        foreach ($this->forcedAttributes[$domNodeName] ?? [] as $attribute => $value) {
            $node->setAttribute($attribute, $value, true);
        }

        $cursor->node->addChild($node);
        $cursor->node = $node;
        ++$outputNodeCount;

        return true;
    }

    private function visitChildren(\Dom\Node|\DOMNode $domNode, Cursor $cursor, \Dom\Document|\DOMDocument $document, \SplObjectStorage $visited, int &$outputNodeCount, ?ParseReport $parseReport): void
    {
        if (!$visited->contains($domNode)) {
            $visited->attach($domNode);
        }

        /** @var \Dom\Node|\DOMNode $child */
        foreach ($domNode->childNodes ?? [] as $child) {
            if (!$child instanceof \Dom\Node && !$child instanceof \DOMNode) {
                continue;
            }

            if ($visited->contains($child)) {
                throw new ParsingException(ParsingException::STAGE_VISIT, 'The parsed DOM contains a node from an earlier visit or a cycle.', $parseReport);
            }

            $visited->attach($child);

            if (null !== ($childDocument = $child->ownerDocument) && $childDocument !== $document) {
                throw new ParsingException(ParsingException::STAGE_VISIT, 'The parsed DOM mixes nodes from different documents.', $parseReport);
            }

            if ('#text' === $child->nodeName) {
                // Add text directly for performance
                $cursor->node->addChild(new TextNode($cursor->node, $child instanceof \Dom\Node ? ($child->textContent ?? '') : $child->nodeValue));
                ++$outputNodeCount;
            } elseif ($child instanceof \Dom\Element || $child instanceof \DOMElement) {
                // Otherwise continue the visit recursively
                // Ignore comments for security reasons (interpreted differently by browsers)
                // Ignore processing instructions (treated as comments)
                $this->visitNode($child, $cursor, $document, $visited, $outputNodeCount, $parseReport);
            }
        }
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

    /**
     * Set attributes from a DOM node to a sanitized node.
     */
    private function setAttributes(string $domNodeName, \Dom\Node|\DOMNode $domNode, Node $node, array $allowedAttributes = []): void
    {
        /** @var iterable<\Dom\Attr|\DOMAttr> $domAttributes */
        if (!$domAttributes = $domNode->attributes?->getIterator()) {
            return;
        }

        foreach ($domAttributes as $attribute) {
            $name = StringSanitizer::htmlLower($attribute->name);

            if (isset($allowedAttributes[$name])) {
                $value = $attribute->value;

                // Sanitize the attribute value if there are attribute sanitizers for it
                $attributeSanitizers = array_merge(
                    $this->attributeSanitizers[$domNodeName][$name] ?? [],
                    $this->attributeSanitizers['*'][$name] ?? [],
                    $this->attributeSanitizers[$domNodeName]['*'] ?? [],
                    $this->attributeSanitizers['*']['*'] ?? [],
                );

                foreach ($attributeSanitizers as $sanitizer) {
                    if (null === $sanitizedValue = $sanitizer->sanitizeAttribute($domNodeName, $name, $value, $this->config)) {
                        continue 2;
                    }
                    $value = $sanitizedValue;
                }

                $node->setAttribute($name, $value);
            }
        }
    }
}
