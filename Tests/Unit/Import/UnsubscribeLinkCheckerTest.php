<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use MauticPlugin\RgeStudioConnectorBundle\Import\UnsubscribeLinkChecker;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnsubscribeLinkCheckerTest extends TestCase
{
    /**
     * The link exactly as RGE Studio exports it passes.
     *
     * @return void
     */
    public function testExportedLinkPasses(): void
    {
        $html = '<body><p><a href="{unsubscribe_url}" target="_blank" rel="noopener">{unsubscribe_url}</a></p></body>';

        self::assertSame([], $this->checker()->check($html));
    }

    /**
     * A quoted attribute holding a closing angle bracket does not hide the link.
     *
     * @return void
     */
    public function testAngleBracketInsideAttributePasses(): void
    {
        self::assertSame([], $this->checker()->check('<body><a title="Leave > now" href="{unsubscribe_url}">Unsubscribe</a></body>'));
    }

    /**
     * The unsubscribe text token as text passes when the setting holds the link.
     *
     * @return void
     */
    public function testUnsubscribeTextPasses(): void
    {
        self::assertSame([], $this->checker()->check('<body><p>{unsubscribe_text}</p></body>'));
    }

    /**
     * Each broken form of the link is reported.
     *
     * @param string $html     Email HTML
     * @param string $expected Words the problem must contain
     *
     * @return void
     */
    #[DataProvider('brokenLinks')]
    public function testBrokenLinksAreReported(string $html, string $expected): void
    {
        $problems = $this->checker()->check($html);

        self::assertNotSame([], $problems);
        self::assertStringContainsString($expected, implode(' ', $problems));
    }

    /**
     * Broken unsubscribe links and the words their problem holds.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function brokenLinks(): iterable
    {
        yield 'no link' => ['<body><p>Hello</p></body>', 'no working unsubscribe link'];
        yield 'glued to an address' => ['<body><a href="https://preview.example.com/{unsubscribe_url}">x</a></body>', 'inside a longer address'];
        yield 'text token in an attribute' => ['<body><a href="{unsubscribe_text}">x</a></body>', 'inside a longer address or inside an attribute'];
        yield 'only in a comment' => ['<body><!-- <a href="{unsubscribe_url}">x</a> --></body>', 'only inside HTML comments'];
        yield 'url encoded' => ['<body><a href="{unsubscribe_url}">x</a><a href="%7Bunsubscribe_url%7D">y</a></body>', 'URL-encoded'];
        yield 'upper case hex entity' => ['<body><a href="{unsubscribe_url}">x</a><p>&#X7B;unsubscribe_text&#X7D;</p></body>', 'HTML entities'];
        yield 'html entities' => ['<body><a href="{unsubscribe_url}">x</a><a href="&#123;unsubscribe_url&#125;">y</a></body>', 'HTML entities'];
        yield 'upper case' => ['<body><a href="{Unsubscribe_URL}">x</a></body>', 'not all in lower case'];
        yield 'pasted address' => ['<body><a href="{unsubscribe_url}">x</a><a href="https://m.example.com/email/unsubscribe/abc">y</a></body>', 'pasted unsubscribe address'];
    }

    /**
     * The link is found whatever the case of the attribute name, the quotes or the spaces around the token.
     *
     * @param string $link Link as written
     *
     * @return void
     */
    #[DataProvider('linkSpellings')]
    public function testLinkSpellingsPass(string $link): void
    {
        self::assertSame([], $this->checker()->check('<body>'.$link.'</body>'));
    }

    /**
     * Links Mautic accepts, written in different ways.
     *
     * @return iterable<string, array{string}>
     */
    public static function linkSpellings(): iterable
    {
        yield 'upper case attribute' => ['<a HREF="{unsubscribe_url}">x</a>'];
        yield 'single quotes' => ["<a href='{unsubscribe_url}'>x</a>"];
        yield 'no quotes' => ['<a href={unsubscribe_url}>x</a>'];
        yield 'spaces around' => ['<a href=" {unsubscribe_url} ">x</a>'];
    }

    /**
     * Every problem is reported once, with its exact wording.
     *
     * @return void
     */
    public function testProblemsAreReportedOnceWithTheirWording(): void
    {
        $html = '<body><a href="{unsubscribe_url}">x</a><p>{UNSUBSCRIBE_TEXT} {Unsubscribe_Url}</p>'
            .'<a href="%7bunsubscribe_url%7d">y</a>'
            .'<a href="https://m.example.com/email/unsubscribe/abc">z</a><a href="https://m.example.com/email/dnc/abc">w</a></body>';

        self::assertSame([
            'An unsubscribe token is not all in lower case, so it gets no List-Unsubscribe header and no tracking.',
            'An unsubscribe token is URL-encoded (%7B...%7D), and Mautic never replaces it.',
            'A pasted unsubscribe address (/email/unsubscribe/) is in the HTML. It only works for one contact.',
            'A pasted unsubscribe address (/email/dnc/) is in the HTML. It only works for one contact.',
        ], $this->checker()->check($html));
    }

    /**
     * Without |URL| in the setting the text token gives no List-Unsubscribe header.
     *
     * @return void
     */
    public function testTextTokenWithoutUrlInSettingIsReported(): void
    {
        $checker = new UnsubscribeLinkChecker(SettingsStub::create(['unsubscribe_text' => 'Unsubscribe here']));

        self::assertStringContainsString('List-Unsubscribe', implode(' ', $checker->check('<body><p>{unsubscribe_text}</p></body>')));
    }

    /**
     * A scheme before a lone token is removed, as Mautic does at send time.
     *
     * @return void
     */
    public function testNormaliseDropsSchemeBeforeLoneToken(): void
    {
        $html = '<a href="https://{unsubscribe_url}">x</a><a href="https://example.com/{unsubscribe_url}">y</a>';

        self::assertSame(
            '<a href="{unsubscribe_url}">x</a><a href="https://example.com/{unsubscribe_url}">y</a>',
            $this->checker()->normalise($html)
        );
    }

    /**
     * Checker over the default settings.
     *
     * @return UnsubscribeLinkChecker
     */
    private function checker(): UnsubscribeLinkChecker
    {
        return new UnsubscribeLinkChecker(SettingsStub::create());
    }
}
