<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\Parser\NativeParser;
use Symfony\Component\HtmlSanitizer\Parser\ParseReport;

class NativeParserTransactionTest extends TestCase
{
    public function testParseFreezesInputDigestDocumentIdentityAndGeneration()
    {
        $parser = new NativeParser('parser-test');
        $html = '<div data-id="42">Hello</div>';

        $node = $parser->parse($html);
        $report = $parser->getLastReport();

        $this->assertInstanceOf(\Dom\Node::class, $node);
        $this->assertNotNull($report);
        $this->assertSame('parser-test', $report->parserId);
        $this->assertSame(1, $report->generation);
        $this->assertSame(hash('sha256', $html), $report->inputDigest);
        $this->assertSame(ParseReport::STAGE_COMMITTED, $report->stage);
        $this->assertTrue($report->isCommitted());
        $this->assertIsString($report->documentId);
        $this->assertStringStartsWith('parser-test:1:', $report->documentId);
        $this->assertSame(\spl_object_id($node->ownerDocument), $report->documentObjectId);
        $this->assertGreaterThan(0, $report->nodeCount);
        $this->assertSame($report, $parser->getLastParseReport());
    }

    public function testEveryParseGetsAFreshDocumentIdentity()
    {
        $parser = new NativeParser('parser-test');

        $first = $parser->parse('<div class="first">A</div>');
        $firstReport = $parser->getLastReport();
        $second = $parser->parse('<div class="second">B</div>');
        $secondReport = $parser->getLastReport();

        $this->assertInstanceOf(\Dom\Node::class, $first);
        $this->assertInstanceOf(\Dom\Node::class, $second);
        $this->assertNotSame($first->ownerDocument, $second->ownerDocument);
        $this->assertSame(1, $firstReport->generation);
        $this->assertSame(2, $secondReport->generation);
        $this->assertNotSame($firstReport->documentId, $secondReport->documentId);

        $firstDiv = $first->getElementsByTagName('div')->item(0);
        $secondDiv = $second->getElementsByTagName('div')->item(0);

        $this->assertInstanceOf(\Dom\Element::class, $firstDiv);
        $this->assertInstanceOf(\Dom\Element::class, $secondDiv);
        $this->assertSame('first', $firstDiv->getAttribute('class'));
        $this->assertSame('second', $secondDiv->getAttribute('class'));
        $this->assertFalse($secondDiv->hasAttribute('data-previous'));
    }

    public function testEmptyParsePublishesACommittedEmptyDocument()
    {
        $parser = new NativeParser('parser-test');

        $this->assertNull($parser->parse(''));

        $report = $parser->getLastReport();

        $this->assertNotNull($report);
        $this->assertSame(ParseReport::STAGE_EMPTY, $report->stage);
        $this->assertTrue($report->isCommitted());
        $this->assertSame(hash('sha256', ''), $report->inputDigest);
        $this->assertSame(0, $report->nodeCount);
        $this->assertNotNull($report->documentId);
    }

    public function testCancelledParseIsNotCommittedAndTheNextParseStartsFresh()
    {
        $parser = new NativeParser('parser-test');

        $parser->cancelNextParse('cancelled by test');

        $this->assertNull($parser->parse('<div data-secret="leak">old</div>'));

        $report = $parser->getLastReport();

        $this->assertNotNull($report);
        $this->assertSame(ParseReport::STAGE_CANCELLED, $report->stage);
        $this->assertFalse($report->isCommitted());
        $this->assertSame('cancelled by test', $report->reason);
        $this->assertNull($report->documentId);

        $node = $parser->parse('<div data-other="safe">new</div>');
        $report = $parser->getLastReport();

        $this->assertInstanceOf(\Dom\Node::class, $node);
        $this->assertSame(2, $report->generation);
        $this->assertTrue($report->isCommitted());

        $div = $node->getElementsByTagName('div')->item(0);

        $this->assertInstanceOf(\Dom\Element::class, $div);
        $this->assertFalse($div->hasAttribute('data-secret'));
        $this->assertSame('safe', $div->getAttribute('data-other'));
    }
}
