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
use Symfony\Component\HtmlSanitizer\Exception\InvalidSanitizationReportException;
use Symfony\Component\HtmlSanitizer\Exception\SanitizationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Parser\NativeParser;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationCancellation;
use Symfony\Component\HtmlSanitizer\Sanitization\SanitizationReport;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class HtmlSanitizerEvidenceTest extends TestCase
{
    public function testEveryOutputNodeMapsBackToAnInputPathPolicyAndAction()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('div', ['class'])
            ->allowElement('p', ['title'])
            ->allowElement('strong')
            ->allowElement('a', ['href'])
            ->allowElement('img', ['src', 'alt'])
            ->allowLinkHosts(['trusted.example'])
            ->forceAttribute('a', 'rel', 'noopener')
        ;

        $input = '<div class="box" onclick="alert(1)"><p title="ok">Hello <strong>world</strong></p><a href="https://evil.example/">bad</a><img src="https://trusted.example/p.png" alt="p"></div>';
        $report = (new HtmlSanitizer($config))->sanitizeWithEvidence($input);

        $this->assertTrue($report->isComplete());
        $this->assertSame(
            '<div class="box"><p title="ok">Hello <strong>world</strong></p><a rel="noopener">bad</a><img src="https://trusted.example/p.png" alt="p" /></div>',
            $report->getOutput(),
        );

        $nodes = $report->getNodes();
        $this->assertCount(8, $nodes);

        foreach ($nodes as $index => $node) {
            $this->assertSame($index, $node->getOutputIndex());
            $this->assertNotSame('', $node->getInputPath());
            $this->assertNotSame('', $node->getOutputPath());
            $this->assertNotSame('', $node->getPolicy());
            $this->assertNotSame('', $node->getAction());
        }

        $div = $nodes[0];
        $this->assertSame('div[1]', $div->getInputPath());
        $this->assertSame('div', $div->getElement());
        $this->assertSame('allow', $div->getPolicy());
        $this->assertSame('keep', $div->getAction());
        $this->assertSame(['class' => 'box'], $div->getAttributes());

        $paragraph = $nodes[1];
        $this->assertSame('div[1] > p[1]', $paragraph->getInputPath());
        $this->assertSame('div[1] > p[1]', $paragraph->getOutputPath());

        $this->assertSame('div[1] > p[1] > #text[1]', $nodes[2]->getInputPath());
        $this->assertSame('text', $nodes[2]->getPolicy());
        $this->assertSame('div[1] > p[1] > strong[1]', $nodes[3]->getInputPath());
        $this->assertSame('div[1] > p[1] > strong[1] > #text[1]', $nodes[4]->getInputPath());

        $link = $nodes[5];
        $this->assertSame('div[1] > a[1]', $link->getInputPath());
        $this->assertSame('allow', $link->getPolicy());
        $this->assertSame('keep', $link->getAction());
        $this->assertSame(['rel' => 'noopener'], $link->getAttributes());

        $actions = array_map(static fn ($modification) => $modification->getAction(), $link->getModifications());
        $this->assertContains('drop', $actions);
        $this->assertContains('force', $actions);

        $hrefDrop = null;
        foreach ($link->getModifications() as $modification) {
            if ('href' === $modification->getName()) {
                $hrefDrop = $modification;
                break;
            }
        }

        $this->assertNotNull($hrefDrop);
        $this->assertSame('attribute', $hrefDrop->getKind());
        $this->assertSame('drop', $hrefDrop->getAction());
        $this->assertSame('attribute-sanitizer', $hrefDrop->getPolicy());

        $image = $nodes[7];
        $this->assertSame('div[1] > img[1]', $image->getInputPath());
        $this->assertSame('https://trusted.example/p.png', $image->getAttribute('src'));
    }

    public function testAttributeSanitizerFailureIsReportedAndDoesNotCommitPartialOutput()
    {
        $attributeSanitizer = new class implements AttributeSanitizerInterface {
            public bool $fail = true;

            public function getSupportedElements(): ?array
            {
                return ['span'];
            }

            public function getSupportedAttributes(): ?array
            {
                return ['data-safe', 'data-danger'];
            }

            public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
            {
                if ($this->fail && 'data-danger' === $attribute) {
                    throw new \DomainException('custom attribute sanitizer failed', 42);
                }

                return strtoupper($value);
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div')
            ->allowElement('span', ['data-safe', 'data-danger'])
            ->withAttributeSanitizer($attributeSanitizer)
        ;

        // Reuse the same parsed DOM for every call to prove that the source
        // tree is frozen and cannot leak a half-sanitized node into a retry.
        $parser = new class implements ParserInterface {
            public ?\Dom\Node $parsed = null;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                return $this->parsed ??= (new NativeParser())->parse($html, $context);
            }
        };

        $sanitizer = new HtmlSanitizer($config, $parser);
        $input = '<div><span data-safe="ok" data-danger="bad">Hello</span></div>';

        $failed = $sanitizer->sanitizeWithEvidence($input);
        $this->assertFalse($failed->isComplete());
        $this->assertSame('', $failed->getOutput());
        $this->assertNull($failed->getOutputOrNull());

        $failure = $failed->getFailure();
        $this->assertNotNull($failure);
        $this->assertSame('attribute-sanitization', $failure->getStage());
        $this->assertSame('div[1] > span[1]', $failure->getNodePath());
        $this->assertSame('span', $failure->getElement());
        $this->assertSame('data-danger', $failure->getAttribute());
        $this->assertSame('allow', $failure->getPolicy());
        $this->assertSame(42, $failure->getCode());
        $this->assertStringContainsString('data-danger', $failure->getFailureMessage());
        $this->assertStringContainsString('div[1] > span[1]', $failure->getFailureMessage());
        $this->assertStringContainsString('custom attribute sanitizer failed', $failure->getFailureMessage());

        $restoredFailure = SanitizationReport::fromArray($failed->toArray());
        $this->assertFalse($restoredFailure->isComplete());
        $this->assertSame('', $restoredFailure->getOutput());
        $this->assertSame('div[1] > span[1]', $restoredFailure->getNodePath());

        try {
            $failed->getCommittedOutput();
            $this->fail('A failed report must not expose committed output.');
        } catch (SanitizationException $e) {
            $this->assertSame($failure, $e->getFailure());
            $this->assertSame('div[1] > span[1]', $e->getNodePath());
        }

        try {
            $sanitizer->sanitize($input);
            $this->fail('The string API must not publish partial output.');
        } catch (\DomainException $e) {
            // Historical string API error semantics are preserved.
            $this->assertSame(42, $e->getCode());
            $this->assertSame('custom attribute sanitizer failed', $e->getMessage());
        }

        $parsed = $parser->parsed;
        $this->assertInstanceOf(\Dom\Element::class, $parsed);
        $span = $parsed->getElementsByTagName('span')->item(0);
        $this->assertInstanceOf(\Dom\Element::class, $span);
        $this->assertSame('ok', $span->getAttribute('data-safe'));
        $this->assertSame('bad', $span->getAttribute('data-danger'));

        $attributeSanitizer->fail = false;
        $expected = '<div><span data-safe="OK" data-danger="BAD">Hello</span></div>';

        $retried = $sanitizer->sanitizeWithEvidence($input);
        $this->assertTrue($retried->isComplete());
        $this->assertSame($expected, $retried->getOutput());
        $this->assertNotSame($failed->getGeneration(), $retried->getGeneration());

        // A new sanitizer is the restart case: it must see the same complete
        // output and the same evidence/generation as the retry.
        $restarted = (new HtmlSanitizer($config, $parser))->sanitizeWithEvidence($input);
        $this->assertTrue($restarted->isComplete());
        $this->assertSame($retried->getOutput(), $restarted->getOutput());
        $this->assertSame($retried->getGeneration(), $restarted->getGeneration());
        $this->assertSame($retried->getPolicyGeneration(), $restarted->getPolicyGeneration());
        $this->assertSame($retried->toArray(), $restarted->toArray());

        $paths = [];
        foreach ($retried->getNodes() as $node) {
            $paths[] = $node->getInputPath();
            $this->assertNotSame('', $node->getPolicy());
            $this->assertNotSame('', $node->getAction());
        }
        $this->assertContains('div[1] > span[1]', $paths);
    }

    public function testCancelledTransactionCannotLeakIntoTheRetry()
    {
        $cancellation = new SanitizationCancellation();
        $attributeSanitizer = new class($cancellation) implements AttributeSanitizerInterface {
            public ?SanitizationCancellation $cancellation;

            public function __construct(?SanitizationCancellation $cancellation)
            {
                $this->cancellation = $cancellation;
            }

            public function getSupportedElements(): ?array
            {
                return ['span'];
            }

            public function getSupportedAttributes(): ?array
            {
                return ['data-stop'];
            }

            public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
            {
                $this->cancellation?->cancel('stop requested by the caller');

                return $value;
            }
        };

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div')
            ->allowElement('span', ['data-stop'])
            ->withAttributeSanitizer($attributeSanitizer)
        ;

        $parser = new class implements ParserInterface {
            public ?\Dom\Node $parsed = null;

            public function parse(string $html, string $context = 'body'): ?\Dom\Node
            {
                return $this->parsed ??= (new NativeParser())->parse($html, $context);
            }
        };

        $input = '<div><span data-stop="yes">Hello</span></div>';
        $sanitizer = new HtmlSanitizer($config, $parser);
        $cancelled = $sanitizer->sanitizeWithEvidence($input, $cancellation);

        $this->assertFalse($cancelled->isComplete());
        $this->assertSame('', $cancelled->getOutput());
        $this->assertNotNull($cancelled->getFailure());
        $this->assertSame('cancelled', $cancelled->getFailure()->getStage());
        $this->assertStringContainsString('stop requested by the caller', $cancelled->getFailure()->getFailureMessage());

        $retryCancellation = new SanitizationCancellation();
        $attributeSanitizer->cancellation = null;
        $retried = $sanitizer->sanitizeWithEvidence($input, $retryCancellation);

        $this->assertTrue($retried->isComplete());
        $this->assertSame('<div><span data-stop="yes">Hello</span></div>', $retried->getOutput());
        $this->assertNotSame($cancelled->getGeneration(), $retried->getGeneration());
    }

    public function testPersistedReportRoundTripsAndRejectsTampering()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p', ['title'])
            ->allowElement('strong')
        ;
        $report = (new HtmlSanitizer($config))->sanitizeWithEvidence('<p title="ok">Hello <strong>world</strong></p>');

        $restored = SanitizationReport::fromArray($report->toArray());
        $this->assertSame($report->getOutput(), $restored->getOutput());
        $this->assertSame($report->getGeneration(), $restored->getGeneration());
        $this->assertSame($report->toArray(), $restored->toArray());

        $fromJson = SanitizationReport::fromJson($report->toJson());
        $this->assertSame($report->toArray(), $fromJson->toArray());

        $unserialized = unserialize(serialize($report));
        $this->assertInstanceOf(SanitizationReport::class, $unserialized);
        $this->assertSame($report->toArray(), $unserialized->toArray());

        $tampered = $report->toArray();
        $tampered['output'] = '<p>incomplete';
        $this->expectException(InvalidSanitizationReportException::class);
        SanitizationReport::fromArray($tampered);
    }

    public function testLegacyStringOutputAndSvgEvidenceRemainIntact()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('svg')
            ->allowElement('circle')
        ;

        $input = '<svg><circle></circle></svg><p>Hello <strong>world</strong></p>';
        $sanitizer = new HtmlSanitizer($config);
        $report = $sanitizer->sanitizeWithEvidence($input);

        $this->assertTrue($report->isComplete());
        $this->assertSame('<svg><circle></circle></svg>', $report->getOutput());
        $this->assertSame($report->getOutput(), $sanitizer->sanitize($input));

        $nodes = $report->getNodes();
        $this->assertSame('svg[1]', $nodes[0]->getInputPath());
        $this->assertSame('svg[1] > circle[1]', $nodes[1]->getInputPath());
    }

    public function testBlockedAncestorIsVisibleInTheChildInputPathButNotTheOutputPath()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')
            ->blockElement('span')
        ;

        $report = (new HtmlSanitizer($config))->sanitizeWithEvidence('<span><p>Hello</p></span>');

        $this->assertTrue($report->isComplete());
        $this->assertSame('<p>Hello</p>', $report->getOutput());
        $this->assertSame('span[1] > p[1]', $report->getNodeByInputPath('span[1] > p[1]')?->getInputPath());
        $this->assertSame('p[1]', $report->getNodeByInputPath('span[1] > p[1]')?->getOutputPath());

        $blocked = false;
        foreach ($report->getModifications() as $modification) {
            if ('span' === $modification->getName() && 'block' === $modification->getAction()) {
                $blocked = true;
            }
        }
        $this->assertTrue($blocked);
    }

    public function testTruncatedInputKeepsACompleteBalancedOutputAndExplicitEvidence()
    {
        $config = (new HtmlSanitizerConfig())->withMaxInputLength(7);
        $report = (new HtmlSanitizer($config))->sanitizeWithEvidence('Hello é');

        $this->assertTrue($report->isComplete());
        $this->assertSame('Hello ', $report->getOutput());
        $this->assertTrue($report->isTruncated());
        $this->assertSame(8, $report->getInputLength());
        $this->assertSame(6, $report->getProcessedLength());

        $truncation = null;
        foreach ($report->getModifications() as $modification) {
            if ('truncate' === $modification->getAction()) {
                $truncation = $modification;
                break;
            }
        }

        $this->assertNotNull($truncation);
        $this->assertSame('input', $truncation->getKind());
        $this->assertSame('max-input-length', $truncation->getPolicy());
        $this->assertSame(8, $truncation->getMetadata()['original_length']);
        $this->assertSame(6, $truncation->getMetadata()['processed_length']);
    }
}
