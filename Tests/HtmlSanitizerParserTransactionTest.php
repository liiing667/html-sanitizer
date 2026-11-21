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
use Symfony\Component\HtmlSanitizer\Exception\ParsingCancelledException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Parser\NativeParser;
use Symfony\Component\HtmlSanitizer\Parser\ParseReport;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;
use Symfony\Component\HtmlSanitizer\SanitizationReport;

class HtmlSanitizerParserTransactionTest extends TestCase
{
    public function testReportPreservesInputDigestParseStageAndNodeCount()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['class'])
        ;
        $sanitizer = new HtmlSanitizer($config);
        $html = '<div class="a">Hello</div>';

        $report = $sanitizer->sanitizeWithReport($html);

        $this->assertTrue($report->isCommitted());
        $this->assertSame(SanitizationReport::STAGE_COMMITTED, $report->stage);
        $this->assertSame(hash('sha256', $html), $report->inputDigest);
        $this->assertSame(hash('sha256', $html), $report->originalInputDigest);
        $this->assertSame(ParseReport::STAGE_COMMITTED, $report->getParseStage());
        $this->assertSame(1, $report->getParserGeneration());
        $this->assertGreaterThan(0, $report->getNodeCount());
        $this->assertGreaterThan(0, $report->outputNodeCount);
        $this->assertSame(hash('sha256', $report->output), $report->outputDigest);
        $this->assertSame($report, $sanitizer->getLastReport());
        $this->assertSame($report->output, $sanitizer->sanitize($html));
    }

    public function testTruncationInsideATagIsNotCommitted()
    {
        $config = (new HtmlSanitizerConfig())
            ->withMaxInputLength(12)
            ->allowElement('div', ['data-secret'])
        ;

        $report = (new HtmlSanitizer($config))->sanitizeWithReport('<div data-secret="leak">hello</div>');

        $this->assertTrue($report->truncated);
        $this->assertFalse($report->isCommitted());
        $this->assertSame(SanitizationReport::STAGE_TRUNCATED, $report->stage);
        $this->assertSame('', $report->getOutput());
    }

    public function testTruncatedPlainTextStillProducesCommittedOutput()
    {
        $config = (new HtmlSanitizerConfig())->withMaxInputLength(7);

        $report = (new HtmlSanitizer($config))->sanitizeWithReport('Hello é');

        $this->assertTrue($report->truncated);
        $this->assertTrue($report->isCommitted());
        $this->assertSame('Hello ', $report->getOutput());
    }

    public function testParserDocumentCannotBeReusedForDifferentInput()
    {
        $parser = new class implements ParserInterface {
            private ?\Dom\Node $cached = null;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                return $this->cached ??= (new NativeParser('cached-parser'))->parse($html, $context);
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['data-secret'])
        ;

        $sanitizer = new HtmlSanitizer($config, $parser);

        $this->assertSame(
            '<div data-secret="leak">first</div>',
            $sanitizer->sanitize('<div data-secret="leak">first</div>')
        );

        $report = $sanitizer->sanitizeWithReport('<div>second</div>');

        $this->assertSame(SanitizationReport::STAGE_STALE_DOCUMENT, $report->stage);
        $this->assertFalse($report->isCommitted());
        $this->assertSame('', $report->getOutput());
    }

    public function testCachedDocumentIsAlsoRejectedAfterSanitizerRestart()
    {
        $parser = new class implements ParserInterface {
            private ?\Dom\Node $cached = null;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                return $this->cached ??= (new NativeParser('cached-parser'))->parse($html, $context);
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['data-secret'])
        ;

        $this->assertSame(
            '<div data-secret="leak">first</div>',
            (new HtmlSanitizer($config, $parser))->sanitize('<div data-secret="leak">first</div>')
        );

        $report = (new HtmlSanitizer($config, $parser))->sanitizeWithReport('<div>second</div>');

        $this->assertSame(SanitizationReport::STAGE_STALE_DOCUMENT, $report->stage);
        $this->assertSame('', $report->getOutput());
    }

    public function testIdenticalInputMayReuseTheCachedDocument()
    {
        $parser = new class implements ParserInterface {
            public ?\Dom\Node $cached = null;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                return $this->cached ??= (new NativeParser('cached-parser'))->parse($html, $context);
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['data-secret'])
        ;

        $sanitizer = new HtmlSanitizer($config, $parser);
        $input = '<div data-secret="same">same</div>';

        $first = $sanitizer->sanitizeWithReport($input);
        $second = $sanitizer->sanitizeWithReport($input);

        $this->assertSame($first->getOutput(), $second->getOutput());
        $this->assertTrue($second->isCommitted());
        $this->assertSame($first->inputDigest, $second->inputDigest);
    }

    public function testMutatedCachedDocumentIsRejectedEvenForTheSameInput()
    {
        $parser = new class implements ParserInterface {
            public ?\Dom\Node $cached = null;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                return $this->cached ??= (new NativeParser('cached-parser'))->parse($html, $context);
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['data-secret'])
        ;

        $sanitizer = new HtmlSanitizer($config, $parser);
        $input = '<div>stable</div>';

        $this->assertSame('<div>stable</div>', $sanitizer->sanitize($input));

        $div = $parser->cached->getElementsByTagName('div')->item(0);
        $this->assertInstanceOf(\Dom\Element::class, $div);
        $div->setAttribute('data-secret', 'leak');

        $report = $sanitizer->sanitizeWithReport($input);

        $this->assertSame(SanitizationReport::STAGE_STALE_DOCUMENT, $report->stage);
        $this->assertFalse($report->isCommitted());
        $this->assertSame('', $report->getOutput());
    }

    public function testCancelledParseCanBeRetriedWithoutPartialOutput()
    {
        $parser = new class implements ParserInterface {
            public bool $cancel = true;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                if ($this->cancel) {
                    throw new ParsingCancelledException('cancelled by test');
                }

                return (new NativeParser('retry-parser'))->parse($html, $context);
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['data-secret'])
        ;

        $sanitizer = new HtmlSanitizer($config, $parser);

        $report = $sanitizer->sanitizeWithReport('<div data-secret="leak">first</div>');

        $this->assertSame(SanitizationReport::STAGE_CANCELLED, $report->stage);
        $this->assertFalse($report->isCommitted());
        $this->assertSame('', $report->getOutput());
        $this->assertSame('cancelled by test', $report->reason);

        $parser->cancel = false;
        $report = $sanitizer->sanitizeWithReport('<div>clean</div>');

        $this->assertTrue($report->isCommitted());
        $this->assertSame('<div>clean</div>', $report->getOutput());
        $this->assertStringNotContainsString('data-secret', $report->getOutput());
    }

    public function testParserFailurePublishesStageEvidenceAndCanBeRetried()
    {
        $parser = new class implements ParserInterface {
            public bool $fail = true;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                if ($this->fail) {
                    throw new \RuntimeException('parse exploded');
                }

                return (new NativeParser('retry-parser'))->parse($html, $context);
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div')
        ;

        $sanitizer = new HtmlSanitizer($config, $parser);

        try {
            $sanitizer->sanitize('<div>first</div>');
            $this->fail('The parser exception should have been propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('parse exploded', $e->getMessage());
        }

        $report = $sanitizer->getLastReport();

        $this->assertNotNull($report);
        $this->assertSame(SanitizationReport::STAGE_PARSE_FAILED, $report->stage);
        $this->assertFalse($report->isCommitted());
        $this->assertSame('', $report->getOutput());
        $this->assertSame('parse exploded', $report->reason);

        $parser->fail = false;

        $this->assertSame('<div>retried</div>', $sanitizer->sanitize('<div>retried</div>'));
    }

    public function testValidFragmentsSvgAndExplicitLenientModeArePreserved()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('div')
            ->allowElement('p')
            ->allowElement('svg')
            ->allowElement('circle', ['cx', 'cy', 'r'])
            ->defaultAction(HtmlSanitizerAction::Allow)
        ;

        $sanitizer = new HtmlSanitizer($config);

        $this->assertSame('<div><p>Hello</p></div>', $sanitizer->sanitize('<div><p>Hello</p></div>'));
        $this->assertSame(
            '<svg><circle cx="5" cy="5" r="4"></circle></svg>',
            $sanitizer->sanitize('<svg><circle cx="5" cy="5" r="4"></circle></svg>')
        );
        $this->assertSame('<x-widget>Hello</x-widget>', $sanitizer->sanitize('<x-widget>Hello</x-widget>'));
    }

    public function testMalformedFragmentIsStillParsedLeniently()
    {
        $config = (new HtmlSanitizerConfig())->allowSafeElements();

        $this->assertSame('<p>Hello</p>', (new HtmlSanitizer($config))->sanitize('<p>Hello'));
    }
}
