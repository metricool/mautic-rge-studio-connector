<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use MauticPlugin\RgeStudioConnectorBundle\Import\LinkChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkCheckerTest extends TestCase
{
    /**
     * Tokens, anchors, valid web addresses and mailto, tel or sms links pass.
     *
     * @dataProvider validLinks
     *
     * @param string $href Link address as written in the HTML
     *
     * @return void
     */
    #[DataProvider('validLinks')]
    public function testValidLinksPass(string $href): void
    {
        self::assertSame([], (new LinkChecker())->invalidLinks('<body><a href="'.$href.'">x</a></body>'));
    }

    /**
     * Link addresses that pass.
     *
     * @return iterable<string, array{string}>
     */
    public static function validLinks(): iterable
    {
        yield 'unsubscribe token' => ['{unsubscribe_url}'];
        yield 'any whole token' => ['{webview_url}'];
        yield 'anchor' => ['#top'];
        yield 'bare anchor' => ['#'];
        yield 'web address' => ['https://example.com'];
        yield 'web address with a path and query' => ['https://example.com/spring offer/?a=1&amp;b=2'];
        yield 'plain http' => ['http://example.com/a'];
        yield 'upper case scheme' => ['HTTPS://example.com'];
        yield 'file transfer' => ['ftp://example.com/file.pdf'];
        yield 'secure file transfer' => ['ftps://example.com/file.pdf'];
        yield 'mail' => ['mailto:hello@example.com'];
        yield 'phone' => ['tel:+34600000000'];
        yield 'text message' => ['sms:+34600000000'];
    }

    /**
     * Anything else is reported, once, in the order it appears.
     *
     * @return void
     */
    public function testInvalidLinksAreReportedOnceInOrder(): void
    {
        $html = '<body><a href="www.example.com">a</a><a href="javascript:alert(1)">b</a><a href="">c</a>'
            .'<a href="https://exa mple.com">d</a><a href="www.example.com">e</a><a href="{unsubscribe_url}x">f</a>'
            .'<a href="https://">g</a><a href="/spring">h</a><a name="top">no address</a></body>';

        self::assertSame(
            ['www.example.com', 'javascript:alert(1)', '', 'https://exa mple.com', '{unsubscribe_url}x', 'https://', '/spring'],
            (new LinkChecker())->invalidLinks($html)
        );
    }

    /**
     * Addresses are read with their HTML entities decoded.
     *
     * @return void
     */
    public function testEntitiesAreDecoded(): void
    {
        self::assertSame(['javascript:alert("x")'], (new LinkChecker())->invalidLinks('<a href="javascript:alert(&quot;x&quot;)">x</a>'));
        self::assertSame(["javascript:alert('x')"], (new LinkChecker())->invalidLinks('<a href="javascript:alert(&amp;apos;x&amp;apos;)">x</a>'));
    }

    /**
     * Empty or blank HTML has no links to report.
     *
     * @return void
     */
    public function testBlankHtmlHasNoLinks(): void
    {
        self::assertSame([], (new LinkChecker())->invalidLinks(''));
        self::assertSame([], (new LinkChecker())->invalidLinks(" \n "));
    }
}
