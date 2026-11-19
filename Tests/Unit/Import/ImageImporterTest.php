<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImageImporter;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImageImporterTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    private string $root;

    /**
     * Create an empty folder that stands in for Mautic's root.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/rge-studio-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/media/images', 0777, true);
    }

    /**
     * Remove the folder and everything written in it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * Every remote image is copied once and the HTML points to the copies only.
     *
     * @return void
     */
    public function testCopiesImagesAndRewritesTheHtml(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            return str_contains($url, 'missing') ? new MockResponse('', ['http_code' => 404]) : new MockResponse(self::PNG);
        });

        $html = '<img src="https://media.beefree.cloud/a.png">'
            .'<img src="https://media.beefree.cloud/a.png" srcset="https://cdn.example.org/b.png 2x">'
            .'<td background="https://media.beefree.cloud/missing.png" style="background-image:url(\'https://cdn.example.org/c.png\')">'
            .'<img src="https://mautic.example.com/media/images/own.png">';

        $result = $this->importer($client)->import($html, microtime(true) + 10);
        $copy   = 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG).'.png';

        self::assertSame(3, $result->copied);
        self::assertStringNotContainsString('media.beefree.cloud', $result->html);
        self::assertStringNotContainsString('cdn.example.org', $result->html);
        self::assertStringContainsString('src="'.$copy.'"', $result->html);
        self::assertStringContainsString('srcset="'.$copy.' 2x"', $result->html);
        self::assertStringContainsString("url('".$copy."')", $result->html);
        self::assertStringContainsString('background=""', $result->html);
        self::assertStringContainsString('https://mautic.example.com/media/images/own.png', $result->html);
        self::assertCount(1, $result->failures);
        self::assertSame('https://media.beefree.cloud/missing.png', $result->failures[0]['url']);
        self::assertFileExists($this->root.'/media/images/rge-studio/'.hash('sha256', self::PNG).'.png');
    }

    /**
     * Less common ways to reference an image are found and rewritten too.
     *
     * @return void
     */
    public function testCopiesImagesReferencedInLessCommonWays(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(self::PNG));
        $copy   = 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG).'.png';

        $html = '<td style="background-image:url(&#x27;https://cdn.example.org/c.png&#x27;)">'
            .'<img srcset="https://cdn.example.org/w_600,c_scale/b.png 2x">'
            .'<img src=https://media.beefree.cloud/unquoted.png>'
            .'<a href="https://media.beefree.cloud/large.png">view</a>';

        $result = $this->importer($client)->import($html, microtime(true) + 10);

        self::assertSame([], $result->failures);
        self::assertStringContainsString('url(&#x27;'.$copy.'&#x27;)', $result->html);
        self::assertStringContainsString('srcset="'.$copy.' 2x"', $result->html);
        self::assertStringContainsString('src='.$copy.'>', $result->html);
        self::assertStringContainsString('href="'.$copy.'"', $result->html);
        self::assertStringNotContainsString('cdn.example.org', $result->html);
        self::assertStringNotContainsString('media.beefree.cloud', $result->html);
    }

    /**
     * Fonts, imported stylesheets and personalised image addresses are left as designed.
     *
     * @return void
     */
    public function testLeavesFontsStylesheetsAndTokenAddressesAlone(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(self::PNG));
        $html   = '<style>@import url(\'https://fonts.example.org/css2?family=Inter\');'
            .'@font-face { font-family: Inter; src: url(https://fonts.example.org/inter.woff2) format(\'woff2\') }</style>'
            .'<img src="https://images.example.org/hello.png?name={contactfield=firstname}">';

        $result = $this->importer($client)->import($html, microtime(true) + 10);

        self::assertSame($html, $result->html);
        self::assertSame(0, $result->copied);
        self::assertSame([], $result->failures);
    }

    /**
     * A file that is not an image is not kept and its address is emptied.
     *
     * @return void
     */
    public function testRejectsFilesThatAreNotImages(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>not an image</html>'));

        $result = $this->importer($client)->import('<img src="https://media.beefree.cloud/page.png">', microtime(true) + 10);

        self::assertSame('<img src="">', $result->html);
        self::assertTrue($result->hasFailures());
    }

    /**
     * No SVG is kept, with or without script, and its address is emptied.
     *
     * @param string $svg Downloaded file
     *
     * @return void
     */
    #[DataProvider('svgFiles')]
    public function testRejectsSvg(string $svg): void
    {
        $client = new MockHttpClient(new MockResponse($svg));

        $result = $this->importer($client)->import('<img src="https://media.beefree.cloud/icon.svg">', microtime(true) + 10);

        self::assertSame('<img src="">', $result->html);
        self::assertTrue($result->hasFailures());
        self::assertSame(0, $result->copied);
    }

    /**
     * A plain SVG, and one with a byte-order mark and a prefixed script tag.
     *
     * @return iterable<string, array{string}>
     */
    public static function svgFiles(): iterable
    {
        yield 'plain' => ['<?xml version="1.0"?>'."\n".'<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>'];
        yield 'prefixed script' => ["\xEF\xBB\xBF".'<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="http://www.w3.org/2000/svg"><x:script>alert(1)</x:script></svg>'];
    }

    /**
     * Importer writing under the temporary root.
     *
     * @param MockHttpClient $client HTTP client that answers the downloads
     *
     * @return ImageImporter
     */
    private function importer(MockHttpClient $client): ImageImporter
    {
        $root  = $this->root;
        $paths = new class($root) extends PathsHelper {
            /**
             * Build the stub.
             *
             * @param string $root Folder that stands in for Mautic's root
             */
            public function __construct(private string $root)
            {
            }

            /**
             * Images path, absolute or relative.
             *
             * @param string $name     Path name
             * @param bool   $fullPath Whether to return the absolute path
             *
             * @return string
             */
            public function getSystemPath($name, $fullPath = false)
            {
                return $fullPath ? $this->root.'/media/images' : 'media/images';
            }
        };

        return new ImageImporter($client, $paths, SettingsStub::create());
    }
}
