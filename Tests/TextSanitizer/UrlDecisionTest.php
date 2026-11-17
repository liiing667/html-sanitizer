<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Tests\TextSanitizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\TextSanitizer\UrlDecision;
use Symfony\Component\HtmlSanitizer\TextSanitizer\UrlRejectionReason;
use Symfony\Component\HtmlSanitizer\TextSanitizer\UrlSanitizer;

class UrlDecisionTest extends TestCase
{
    #[DataProvider('provideDecisions')]
    public function testDecide(string $input, bool $allowRelative, bool $expectedAccepted, ?UrlRejectionReason $expectedReason, ?string $expectedUrl)
    {
        $decision = UrlSanitizer::decide($input, ['http', 'https'], false, ['trusted.com'], $allowRelative);

        $this->assertSame($expectedAccepted, $decision->isAccepted());
        $this->assertSame($expectedReason, $decision->getReason());
        $this->assertSame($expectedUrl, $decision->getSanitizedUrl());
    }

    public static function provideDecisions(): iterable
    {
        yield 'accepted URL' => [
            'input' => 'https://trusted.com/login',
            'allowRelative' => false,
            'expectedAccepted' => true,
            'expectedReason' => null,
            'expectedUrl' => 'https://trusted.com/login',
        ];

        yield 'forbidden scheme' => [
            'input' => 'javascript:alert(1)',
            'allowRelative' => false,
            'expectedAccepted' => false,
            'expectedReason' => UrlRejectionReason::SchemeNotAllowed,
            'expectedUrl' => null,
        ];

        yield 'forbidden host' => [
            'input' => 'https://untrusted.com/',
            'allowRelative' => false,
            'expectedAccepted' => false,
            'expectedReason' => UrlRejectionReason::HostNotAllowed,
            'expectedUrl' => null,
        ];

        yield 'relative link not allowed' => [
            'input' => '/login',
            'allowRelative' => false,
            'expectedAccepted' => false,
            'expectedReason' => UrlRejectionReason::RelativeNotAllowed,
            'expectedUrl' => null,
        ];

        yield 'relative link allowed' => [
            'input' => '/login',
            'allowRelative' => true,
            'expectedAccepted' => true,
            'expectedReason' => null,
            'expectedUrl' => '/login',
        ];

        yield 'malformed URL' => [
            'input' => ':invalid',
            'allowRelative' => false,
            'expectedAccepted' => false,
            'expectedReason' => UrlRejectionReason::RelativeNotAllowed,
            'expectedUrl' => null,
        ];

        yield 'unparseable URL' => [
            'input' => 'http://[::1',
            'allowRelative' => false,
            'expectedAccepted' => false,
            'expectedReason' => UrlRejectionReason::Malformed,
            'expectedUrl' => null,
        ];

        yield 'denied character' => [
            'input' => "https://trusted.com/foo\u{FEFF}bar",
            'allowRelative' => false,
            'expectedAccepted' => false,
            'expectedReason' => UrlRejectionReason::DeniedCharacter,
            'expectedUrl' => null,
        ];

        yield 'encoded denied character' => [
            'input' => 'https://trusted.com/%E2%80%AE/bar',
            'allowRelative' => false,
            'expectedAccepted' => false,
            'expectedReason' => UrlRejectionReason::DeniedCharacter,
            'expectedUrl' => null,
        ];

        yield 'invalid structure' => [
            'input' => 'https:untrusted.com/login',
            'allowRelative' => false,
            'expectedAccepted' => false,
            'expectedReason' => UrlRejectionReason::InvalidStructure,
            'expectedUrl' => null,
        ];
    }

    public function testDecideEmptyInput()
    {
        $decision = UrlSanitizer::decide(null);

        $this->assertFalse($decision->isAccepted());
        $this->assertSame(UrlRejectionReason::Malformed, $decision->getReason());
        $this->assertNull($decision->getSanitizedUrl());
    }

    public function testNestedViewSourcePropagatesReason()
    {
        $decision = UrlSanitizer::decide('view-source:javascript:alert(1)', ['https']);

        $this->assertFalse($decision->isAccepted());
        $this->assertSame(UrlRejectionReason::SchemeNotAllowed, $decision->getReason());
    }

    public function testDecisionFactories()
    {
        $accepted = UrlDecision::accept('https://trusted.com/');
        $this->assertTrue($accepted->isAccepted());
        $this->assertSame('https://trusted.com/', $accepted->getSanitizedUrl());
        $this->assertNull($accepted->getReason());

        $rejected = UrlDecision::reject(UrlRejectionReason::HostNotAllowed);
        $this->assertFalse($rejected->isAccepted());
        $this->assertNull($rejected->getSanitizedUrl());
        $this->assertSame(UrlRejectionReason::HostNotAllowed, $rejected->getReason());
    }
}
