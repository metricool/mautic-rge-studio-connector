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
        yield 'html entities' => ['<body><a href="{unsubscribe_url}">x</a><a href="&#123;unsubscribe_url&#125;">y</a></body>', 'HTML entities'];
        yield 'upper case' => ['<body><a href="{Unsubscribe_URL}">x</a></body>', 'not all in lower case'];
        yield 'pasted address' => ['<body><a href="{unsubscribe_url}">x</a><a href="https://m.example.com/email/unsubscribe/abc">y</a></body>', 'pasted unsubscribe address'];
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
