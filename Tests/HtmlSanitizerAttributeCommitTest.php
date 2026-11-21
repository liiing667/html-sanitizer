<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\Exception\AttributeSanitizationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Parser\NativeParser;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class HtmlSanitizerAttributeCommitTest extends TestCase
{
    public function testValidCustomAttributeAndEmptyAttributeArePreserved()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['data-token', 'data-empty'])
            ->withAttributeSanitizer(new class implements AttributeSanitizerInterface {
                public function getSupportedElements(): ?array
                {
                    return ['div'];
                }

                public function getSupportedAttributes(): ?array
                {
                    return ['data-token', 'data-empty'];
                }

                public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
                {
                    return strtoupper($value);
                }
            })
        ;

        $this->assertSame(
            '<div data-token="ABC" data-empty>Hello</div>',
            (new HtmlSanitizer($config))->sanitize('<div data-token="abc" data-empty="">Hello</div>')
        );
    }

    public function testCustomSanitizerFailureDoesNotCommitPartialNodeAndCanBeRetried()
    {
        $customSanitizer = new class implements AttributeSanitizerInterface {
            public bool $failOnDanger = true;

            public function getSupportedElements(): ?array
            {
                return ['div'];
            }

            public function getSupportedAttributes(): ?array
            {
                return ['data-safe', 'data-token', 'data-danger'];
            }

            public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
            {
                if ($this->failOnDanger && 'data-danger' === $attribute) {
                    throw new \DomainException('custom attribute sanitizer failed', 42);
                }

                return strtoupper($value);
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['data-safe', 'data-token', 'data-danger'])
            ->withAttributeSanitizer($customSanitizer)
        ;

        // Reuse the same parsed DOM for every call to make sure a failed visit
        // cannot leave attributes behind for the next one.
        $parser = new class implements ParserInterface {
            public ?\Dom\Node $parsed = null;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                return $this->parsed ??= (new NativeParser())->parse($html, $context);
            }
        };

        $sanitizer = new HtmlSanitizer($config, $parser);
        $input = '<div data-safe="safe" data-token="token" data-danger="danger">Hello</div>';

        try {
            $sanitizer->sanitize($input);
            $this->fail('The custom attribute sanitizer should have failed.');
        } catch (AttributeSanitizationException $e) {
            $this->assertSame('div', $e->getElementName());
            $this->assertSame('data-danger', $e->getAttributeName());
            $this->assertStringContainsString('div[1]', $e->getNodePath());
            $this->assertSame(AttributeSanitizationException::STAGE, $e->getStage());
            $this->assertSame(42, $e->getCode());
            $this->assertInstanceOf(\DomainException::class, $e->getPrevious());
            $this->assertSame('custom attribute sanitizer failed', $e->getPrevious()->getMessage());
            $this->assertStringContainsString('data-danger', $e->getMessage());
            $this->assertStringContainsString('div[1]', $e->getMessage());
        }

        $parsed = $parser->parsed;
        $this->assertInstanceOf(\Dom\Element::class, $parsed);

        $div = $parsed->getElementsByTagName('div')->item(0);
        $this->assertInstanceOf(\Dom\Element::class, $div);
        $this->assertSame('safe', $div->getAttribute('data-safe'));
        $this->assertSame('token', $div->getAttribute('data-token'));
        $this->assertSame('danger', $div->getAttribute('data-danger'));

        $customSanitizer->failOnDanger = false;
        $expected = '<div data-safe="SAFE" data-token="TOKEN" data-danger="DANGER">Hello</div>';

        $this->assertSame($expected, $sanitizer->sanitize($input));
        $this->assertSame($expected, (new HtmlSanitizer($config, $parser))->sanitize($input));
    }

    public function testCustomSanitizerFailureReportsNestedNodePath()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('div')
            ->allowElement('span', ['data-danger'])
            ->withAttributeSanitizer(new class implements AttributeSanitizerInterface {
                public function getSupportedElements(): ?array
                {
                    return ['span'];
                }

                public function getSupportedAttributes(): ?array
                {
                    return ['data-danger'];
                }

                public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
                {
                    throw new \RuntimeException('nested failure');
                }
            })
        ;

        try {
            (new HtmlSanitizer($config))->sanitize('<div><span data-danger="bad">Hello</span></div>');
            $this->fail('The custom attribute sanitizer should have failed.');
        } catch (AttributeSanitizationException $e) {
            $this->assertSame('span', $e->getElementName());
            $this->assertSame('data-danger', $e->getAttributeName());
            $this->assertStringContainsString('div[1] > span[1]', $e->getNodePath());
        }
    }

    public function testOrdinaryOutputIsUnchanged()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
        ;

        $this->assertSame(
            '<p>Hello <strong>world</strong></p>',
            (new HtmlSanitizer($config))->sanitize('<p>Hello <strong>world</strong></p>')
        );
    }
}
