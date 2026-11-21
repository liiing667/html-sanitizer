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

use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationAborted;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationFailure;
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

    public function visit(\Dom\Node|\DOMNode $domNode): ?NodeInterface
    {
        return $this->visitWithTrace($domNode, new SanitizationTrace());
    }

    /**
     * Visits a frozen input tree and records node-level evidence in $trace.
     */
    public function visitWithTrace(\Dom\Node|\DOMNode $domNode, SanitizationTrace $trace): ?NodeInterface
    {
        $cursor = new Cursor(new DocumentNode());
        $this->visitChildren($domNode, $cursor, $trace, '');

        return $cursor->node;
    }

    private function visitNode(\Dom\Node|\DOMNode $domNode, Cursor $cursor, SanitizationTrace $trace, string $inputPath): void
    {
        $nodeName = StringSanitizer::htmlLower($domNode->nodeName);
        $trace->enterLocation($inputPath, $nodeName);

        // Visit recursively if the node was not dropped
        if ($this->enterNode($nodeName, $domNode, $cursor, $trace, $inputPath)) {
            $this->visitChildren($domNode, $cursor, $trace, $inputPath);
            $cursor->node = $cursor->node->getParent();
        }

        $trace->leaveLocation();
    }

    private function enterNode(string $domNodeName, \Dom\Node|\DOMNode $domNode, Cursor $cursor, SanitizationTrace $trace, string $inputPath): bool
    {
        if (!\array_key_exists($domNodeName, $this->elementsConfig)) {
            $action = $this->defaultAction;
            $allowedAttributes = [];
            $policySource = 'default';
        } else {
            if (\is_array($this->elementsConfig[$domNodeName])) {
                $action = HtmlSanitizerAction::Allow;
                $allowedAttributes = $this->elementsConfig[$domNodeName];
            } else {
                $action = $this->elementsConfig[$domNodeName];
                $allowedAttributes = [];
            }
            $policySource = 'explicit';
        }

        if (HtmlSanitizerAction::Drop === $action) {
            $trace->recordModification('element', 'drop', $inputPath, $action->value, $domNodeName, metadata: ['source' => $policySource]);

            return false;
        }

        // Element should be blocked, retaining its children
        if (HtmlSanitizerAction::Block === $action) {
            $node = new BlockedNode($cursor->node);

            $cursor->node->addChild($node);
            $trace->inheritOutputPath($node, $cursor->node);
            $trace->recordModification('element', 'block', $inputPath, $action->value, $domNodeName, metadata: ['source' => $policySource]);
            $cursor->node = $node;

            return true;
        }

        // Otherwise create the node
        $node = new Node($cursor->node, $domNodeName);
        $trace->recordModification('element', 'keep', $inputPath, $action->value, $domNodeName, metadata: ['source' => $policySource]);
        $this->setAttributes($domNodeName, $domNode, $node, $allowedAttributes, $trace, $inputPath, $action->value);

        // Force configured attributes
        foreach ($this->forcedAttributes[$domNodeName] ?? [] as $attribute => $value) {
            $node->setAttribute($attribute, $value, true);
            $trace->recordModification('attribute', 'force', $inputPath, 'forced-attributes', $attribute, null, $value);
        }

        $outputPath = $trace->outputPathFor($cursor->node, $domNodeName);
        $cursor->node->addChild($node);
        $cursor->node = $node;
        $trace->registerOutputPath($node, $outputPath);
        $trace->addNode(
            \count($trace->getNodes()),
            $outputPath,
            $inputPath,
            'element',
            $domNodeName,
            $action->value,
            'keep',
            array_filter($node->getAttributes(), static fn (?string $value): bool => null !== $value),
            $trace->getModificationsForPath($inputPath),
        );

        return true;
    }

    private function visitChildren(\Dom\Node|\DOMNode $domNode, Cursor $cursor, SanitizationTrace $trace, string $inputPath): void
    {
        $childIndexes = [];

        /** @var \Dom\Node|\DOMNode $child */
        foreach ($domNode->childNodes ?? [] as $child) {
            $childName = StringSanitizer::htmlLower($child->nodeName);
            $childIndex = $childIndexes[$childName] = ($childIndexes[$childName] ?? 0) + 1;
            $childPath = SanitizationTrace::boundPath(
                '' === $inputPath ? $childName.'['.$childIndex.']' : $inputPath.' > '.$childName.'['.$childIndex.']'
            );
            $trace->checkCancellation($childPath, $childName);

            if ('#text' === $child->nodeName) {
                // Add text directly for performance
                $this->addTextNode(
                    $cursor,
                    $child instanceof \Dom\Node ? ($child->textContent ?? '') : $child->nodeValue,
                    $trace,
                    $childPath,
                );
            } elseif ($child instanceof \Dom\Element || $child instanceof \DOMElement) {
                // Otherwise continue the visit recursively
                // Ignore comments for security reasons (interpreted differently by browsers)
                // Ignore processing instructions (treated as comments)
                $this->visitNode($child, $cursor, $trace, $childPath);
            } else {
                $trace->recordModification('comment', 'drop', $childPath, 'drop', $child->nodeName);
            }
        }
    }

    private function addTextNode(Cursor $cursor, string $text, SanitizationTrace $trace, string $inputPath): void
    {
        $outputPath = $trace->outputPathFor($cursor->node, '#text');
        $node = new TextNode($cursor->node, $text);
        $cursor->node->addChild($node);
        $trace->registerOutputPath($node, $outputPath);

        $encoded = StringSanitizer::encodeHtmlEntities($text);
        $action = $encoded === $text ? 'keep' : 'encode';
        $trace->recordModification('text', $action, $inputPath, 'text', metadata: [
            'input_length' => \strlen($text),
            'output_length' => \strlen($encoded),
        ]);
        $trace->addNode(
            \count($trace->getNodes()),
            $outputPath,
            $inputPath,
            'text',
            null,
            'text',
            $action,
            [],
            $trace->getModificationsForPath($inputPath),
        );
    }

    /**
     * Set attributes from a DOM node to a sanitized node.
     */
    private function setAttributes(string $domNodeName, \Dom\Node|\DOMNode $domNode, Node $node, array $allowedAttributes, SanitizationTrace $trace, string $inputPath, string $policy): void
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

                if (!$attributeSanitizers) {
                    $node->setAttribute($name, $value);
                    $trace->recordModification('attribute', 'keep', $inputPath, 'allowed-attributes', $name, $value, $value);

                    continue;
                }

                foreach ($attributeSanitizers as $sanitizer) {
                    try {
                        $sanitizedValue = $sanitizer->sanitizeAttribute($domNodeName, $name, $value, $this->config);
                    } catch (\Throwable $e) {
                        throw new SanitizationAborted(new SanitizationFailure(
                            'attribute-sanitization',
                            \sprintf('Attribute sanitizer failed while processing "%s" of <%s>: %s', $name, $domNodeName, $e->getMessage()),
                            $inputPath,
                            element: $domNodeName,
                            attribute: $name,
                            policy: $policy,
                            sanitizer: $sanitizer::class,
                            exceptionClass: $e::class,
                            code: (int) $e->getCode(),
                        ), $e);
                    }

                    if (null === $sanitizedValue) {
                        $trace->recordModification('attribute', 'drop', $inputPath, 'attribute-sanitizer', $name, $value, null, $sanitizer::class);

                        continue 2;
                    }

                    $trace->recordModification(
                        'attribute',
                        $sanitizedValue === $value ? 'keep' : 'rewrite',
                        $inputPath,
                        'attribute-sanitizer',
                        $name,
                        $value,
                        $sanitizedValue,
                        $sanitizer::class,
                    );
                    $value = $sanitizedValue;
                    $trace->checkCancellation($inputPath, $domNodeName);
                }

                $node->setAttribute($name, $value);
            } else {
                $trace->recordModification('attribute', 'drop', $inputPath, 'allowed-attributes', $name, $attribute->value, null);
            }
        }
    }
}
