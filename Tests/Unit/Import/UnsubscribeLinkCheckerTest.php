<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use Mautic\EmailBundle\Validator\ValidEmailLinks;
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
     * @dataProvider brokenLinks
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
        yield 'pasted address' => ['<body><a href="{unsubscribe_url}">x</a><a href="https://m.example.com/email/unsubscribe/abc">y</a></body>', 'pasted unsubscribe address'];
    }

    /**
     * The link is found whatever the case of the attribute name, the quotes or the spaces around the token.
     *
     * @dataProvider linkSpellings
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
            .'<a href="%7bunsubscribe_url%7d">y</a><p>&#123;unsubscribe_text&#125;</p>'
            .'<a href="https://m.example.com/email/unsubscribe/abc">z</a><a href="https://m.example.com/email/dnc/abc">w</a></body>';

        $expected = [
            'An unsubscribe token is URL-encoded (%7B...%7D), and Mautic never replaces it.',
            'An unsubscribe token is written with HTML entities, and Mautic never replaces it.',
            'A pasted unsubscribe address (/email/unsubscribe/) is in the HTML. It only works for one contact.',
            'A pasted unsubscribe address (/email/dnc/) is in the HTML. It only works for one contact.',
        ];
        self::assertSame($expected, $this->checker()->check($html));

        if ($this->hasHeaderRule()) {
            array_unshift($expected, 'An unsubscribe token is not all in lower case, so it gets no List-Unsubscribe header and no tracking.');
            self::assertSame($expected, $this->checker(true)->check($html));
        }
    }

    /**
     * The header rule is found exactly in the Mautic versions that also have Mautic's own link rule (7.2 and later).
     *
     * @return void
     */
    public function testHeaderRuleIsFoundFromMautic72(): void
    {
        self::assertSame(class_exists(ValidEmailLinks::class), UnsubscribeLinkChecker::mauticHasHeaderRule());
    }

    /**
     * Where Mautic adds the header to every email, a text token without |URL| in the setting and a token in
     * capitals are not reported.
     *
     * @return void
     */
    public function testHeaderIsNotCheckedWithoutTheHeaderRule(): void
    {
        $html = '<body><p>{unsubscribe_text}</p><a href="{Unsubscribe_URL}">x</a></body>';

        self::assertSame([], $this->checker(false, ['unsubscribe_text' => 'Unsubscribe here'])->check($html));
    }

    /**
     * Where Mautic adds the header only when the HTML asks for it, a text token without |URL| in the setting
     * and a token in capitals are reported.
     *
     * @return void
     */
    public function testHeaderIsCheckedWithTheHeaderRule(): void
    {
        if (!$this->hasHeaderRule()) {
            self::markTestSkipped('This Mautic adds the List-Unsubscribe header to every email.');
        }

        $html = '<body><p>{unsubscribe_text}</p><a href="{Unsubscribe_URL}">x</a></body>';

        self::assertSame([
            'Mautic will send no List-Unsubscribe header. Use {unsubscribe_url} in lower case, or an unsubscribe text setting that holds |URL|.',
            'An unsubscribe token is not all in lower case, so it gets no List-Unsubscribe header and no tracking.',
        ], $this->checker(true, ['unsubscribe_text' => 'Unsubscribe here'])->check($html));
        self::assertSame([], $this->checker(true)->check('<body><p>{unsubscribe_text}</p></body>'));
        self::assertSame([], $this->checker(true)->check('<body><!-- {unsubscribe_text} --><a href="{unsubscribe_url}">x</a></body>'));
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
     * When the pattern cannot run, as when PCRE hits its backtracking limit, the HTML is kept as it is.
     *
     * @return void
     */
    public function testNormaliseKeepsTheHtmlWhenThePatternFails(): void
    {
        $html      = '<a href="https://{unsubscribe_url}">x</a>'.str_repeat(' href=', 2000);
        $jit       = ini_get('pcre.jit');
        $backtrack = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $result = $this->checker()->normalise($html);
        } finally {
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
            ini_set('pcre.backtrack_limit', false === $backtrack ? '1000000' : $backtrack);
        }

        self::assertSame($html, $result);
    }

    /**
     * Checker over the given settings.
     *
     * @param bool                 $headerRule Whether Mautic adds List-Unsubscribe only when the HTML asks for it
     * @param array<string, mixed> $parameters Settings to change
     *
     * @return UnsubscribeLinkChecker
     */
    private function checker(bool $headerRule = false, array $parameters = []): UnsubscribeLinkChecker
    {
        return new UnsubscribeLinkChecker(SettingsStub::create($parameters), $headerRule);
    }

    /**
     * Whether this Mautic has the rule that adds List-Unsubscribe only when the HTML asks for it.
     *
     * @return bool
     */
    private function hasHeaderRule(): bool
    {
        return UnsubscribeLinkChecker::mauticHasHeaderRule();
    }
}
