<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImageFiles;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImageImporter;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SpyLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class ImageImporterTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    private string $root;

    private SpyLogger $logger;

    /**
     * Create an empty folder that stands in for Mautic's root, and a logger to read.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/rge-studio-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/media/images', 0777, true);
        $this->logger = new SpyLogger();
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
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
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
     * Images next to an imported stylesheet or a font, and an image address at the very start of the HTML, are
     * copied, while the stylesheet and the font are left alone.
     *
     * @return void
     */
    public function testImagesNextToFontsAreCopied(): void
    {
        $requests = $this->log();
        $client   = $this->recording($requests, static fn (string $method, string $url): MockResponse => new MockResponse(self::PNG.$url));
        $html     = 'https://media.beefree.cloud/first.png<style>@import \'https://fonts.example.org/a.css\';url(\'https://cdn.example.org/b.png\')'
            .'@font-face { src: url(https://fonts.example.org/f.woff2) }</style>';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $urls = array_map(static fn (array $request): string => $request['url'], $requests->getArrayCopy());
        sort($urls);
        self::assertSame(['https://cdn.example.org/b.png', 'https://media.beefree.cloud/first.png'], $urls);
        self::assertStringContainsString("@import 'https://fonts.example.org/a.css';", $result->html);
        self::assertStringContainsString('url(https://fonts.example.org/f.woff2)', $result->html);
        self::assertSame([], $result->failures);
    }

    /**
     * A designer storage address in a CSS url() quoted with HTML entities ends before the closing entity, so the
     * copy keeps the quotes around it.
     *
     * @return void
     */
    public function testDesignerAddressesStopAtEncodedQuotes(): void
    {
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(self::PNG.$url));
        $html   = '<td style="background:url(&quot;https://media.beefree.cloud/a.png&quot;)">'
            .'<td style="background:url(&#39;https://media.beefree.cloud/b.png&#39;)">'
            .'<td style="background:url(&#x22;https://media.beefree.cloud/c.png&#x22;)">'
            .'<td style="background:url(&apos;https://media.beefree.cloud/d.png&apos;)">';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $copy = fn (string $name): string => 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG.'https://media.beefree.cloud/'.$name).'.png';
        self::assertSame([], $result->failures);
        self::assertSame(
            '<td style="background:url(&quot;'.$copy('a.png').'&quot;)">'
            .'<td style="background:url(&#39;'.$copy('b.png').'&#39;)">'
            .'<td style="background:url(&#x22;'.$copy('c.png').'&#x22;)">'
            .'<td style="background:url(&apos;'.$copy('d.png').'&apos;)">',
            $result->html
        );
    }

    /**
     * An encoded quote that did not open the address is part of it, in a link and in a CSS url() alike.
     *
     * @return void
     */
    public function testDesignerAddressesKeepOtherEncodedQuotes(): void
    {
        $requests = $this->log();
        $client   = $this->recording($requests, static fn (string $method, string $url): MockResponse => new MockResponse(self::PNG.$url));
        $html     = '<a href="https://media.beefree.cloud/Bob&apos;s.png">view</a>'
            .'<td style="background:url(&quot;https://media.beefree.cloud/Ann&#39;s.png&quot;)">';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $urls = array_map(static fn (array $request): string => $request['url'], $requests->getArrayCopy());
        sort($urls);
        $copy = fn (string $name): string => 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG.'https://media.beefree.cloud/'.$name).'.png';
        self::assertSame(['https://media.beefree.cloud/Ann\'s.png', 'https://media.beefree.cloud/Bob\'s.png'], $urls);
        self::assertSame([], $result->failures);
        self::assertSame(
            '<a href="'.$copy('Bob\'s.png').'">view</a><td style="background:url(&quot;'.$copy('Ann\'s.png').'&quot;)">',
            $result->html
        );
    }

    /**
     * Only a designer host or one of its subdomains counts, with a port or not, so another host that holds its
     * name is left as designed and not flagged.
     *
     * @return void
     */
    public function testDesignerHostsMatchOnlyAsWholeNames(): void
    {
        $requests = $this->log();
        $client   = $this->recording($requests, static fn (string $method, string $url): MockResponse => new MockResponse(self::PNG.$url));
        $html     = '<a href="https://imgdist.com.example.org/offer">offer</a><a href="https://notimgdist.com/x">x</a>'
            .'<a href="https://cdn.imgdist.com:8443/p.png">p</a>';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $copy = 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG.'https://cdn.imgdist.com:8443/p.png').'.png';
        self::assertSame(['https://cdn.imgdist.com:8443/p.png'], array_map(static fn (array $request): string => $request['url'], $requests->getArrayCopy()));
        self::assertSame([], $result->failures);
        self::assertSame('<a href="https://imgdist.com.example.org/offer">offer</a><a href="https://notimgdist.com/x">x</a><a href="'.$copy.'">p</a>', $result->html);
    }

    /**
     * A server that never sends anything stops the copies at the deadline, and the image is reported as not copied
     * in time.
     *
     * @return void
     */
    public function testStalledDownloadStopsAtTheDeadline(): void
    {
        $client = new class() implements HttpClientInterface {
            /**
             * Start a download that never receives anything.
             *
             * @param string               $method  HTTP method
             * @param string               $url     Address
             * @param array<string, mixed> $options Request options
             *
             * @return ResponseInterface
             */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return (new MockHttpClient(new MockResponse('')))->request($method, $url, $options);
            }

            /**
             * Report an idle timeout for every download, again and again.
             *
             * @param ResponseInterface|iterable<ResponseInterface> $responses Downloads to watch
             * @param float|null                                    $timeout   Idle timeout in seconds
             *
             * @return ResponseStreamInterface
             */
            public function stream($responses, ?float $timeout = null): ResponseStreamInterface
            {
                $list = $responses instanceof ResponseInterface ? [$responses] : $responses;

                return new ResponseStream((static function () use ($list): \Generator {
                    while (true) {
                        foreach ($list as $response) {
                            yield $response => new ErrorChunk(0, 'idle');
                        }
                    }
                })());
            }

            /**
             * The same client, whatever the options.
             *
             * @param array<string, mixed> $options Default options
             *
             * @return static
             */
            public function withOptions(array $options): static
            {
                return $this;
            }
        };

        $start  = microtime(true);
        $result = $this->importer($client)->import('<img src="https://cdn.example.org/slow.png">', $start + 0.6);

        self::assertLessThan(3.0, microtime(true) - $start);
        self::assertSame([['url' => 'https://cdn.example.org/slow.png', 'reason' => 'Not copied in time.']], $result->failures);
        self::assertSame('<img src="">', $result->html);
    }

    /**
     * A copy that cannot take its place in the media folder is reported, its address is emptied and the PHP error
     * is logged.
     *
     * @return void
     */
    public function testCopyThatCannotBeSavedIsReported(): void
    {
        $target = $this->root.'/media/images/rge-studio/'.hash('sha256', self::PNG).'.png';
        mkdir($target, 0777, true);

        $result = $this->importer(new MockHttpClient(new MockResponse(self::PNG)))
            ->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        self::assertSame(0, $result->copied);
        self::assertSame([['url' => 'https://cdn.example.org/a.png', 'reason' => 'The image could not be saved in the media folder.']], $result->failures);
        self::assertSame('<img src="">', $result->html);
        self::assertSame([], glob($this->root.'/media/images/rge-studio/.rge*') ?: []);
        self::assertCount(1, $this->logger->records);
        self::assertSame('RGE Studio connector: an image could not be saved in the media folder.', $this->logger->records[0]['message']);
        self::assertSame($target, $this->logger->records[0]['context']['to']);
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
        self::assertSame([], glob($this->root.'/media/images/rge-studio/.rge*') ?: []);
    }

    /**
     * An image already in the media folder is used as it is, and the new download of it is removed.
     *
     * @return void
     */
    public function testImageAlreadySavedIsReused(): void
    {
        $name = hash('sha256', self::PNG).'.png';
        mkdir($this->root.'/media/images/rge-studio');
        file_put_contents($this->root.'/media/images/rge-studio/'.$name, self::PNG);

        $result = $this->importer(new MockHttpClient(new MockResponse(self::PNG)))
            ->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        self::assertSame(1, $result->copied);
        self::assertSame([], $result->failures);
        self::assertSame('<img src="https://mautic.example.com/media/images/rge-studio/'.$name.'">', $result->html);
        self::assertSame([], glob($this->root.'/media/images/rge-studio/.rge*') ?: []);
        self::assertSame([], $this->logger->records);
    }

    /**
     * No SVG is kept, with or without script, and its address is emptied.
     *
     * @dataProvider svgFiles
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
     * Each download asks for an image, follows few redirects and stays within the time left.
     *
     * @return void
     */
    public function testRequestsCarryTheDownloadOptions(): void
    {
        $requests = $this->log();
        $this->importer($this->recording($requests, static fn (): MockResponse => new MockResponse(self::PNG)))
            ->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        self::assertCount(1, $requests);
        $request = $requests->getArrayCopy()[0];
        $options = $request['options'];
        $headers = $options['normalized_headers'];
        self::assertIsArray($headers);
        self::assertSame('GET', $request['method']);
        self::assertSame('https://cdn.example.org/a.png', $request['url']);
        self::assertSame(3, $options['max_redirects']);
        self::assertFalse($options['buffer']);
        self::assertSame(['Accept: image/*'], $headers['accept']);
        self::assertSame('https://cdn.example.org/a.png', $options['user_data']);
        self::assertLessThanOrEqual(10.0, $options['timeout']);
        self::assertGreaterThan(9.0, $options['timeout']);
        self::assertGreaterThan(25.0, $options['max_duration']);
        self::assertLessThanOrEqual(30.0, $options['max_duration']);
    }

    /**
     * Server errors and rate limits are tried again, other answers are not.
     *
     * @dataProvider answers
     *
     * @param int $status   Status the image server answers
     * @param int $attempts Downloads expected
     *
     * @return void
     */
    #[DataProvider('answers')]
    public function testOnlyServerErrorsAreTriedAgain(int $status, int $attempts): void
    {
        $requests = $this->log();
        $result   = $this->importer($this->recording($requests, static fn (): MockResponse => new MockResponse('', ['http_code' => $status])))
            ->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        self::assertCount($attempts, $requests);
        self::assertSame([['url' => 'https://cdn.example.org/a.png', 'reason' => 'The image server answered '.$status.'.']], $result->failures);
    }

    /**
     * Statuses and how many downloads each one gets.
     *
     * @return iterable<string, array{int, int}>
     */
    public static function answers(): iterable
    {
        yield 'service unavailable' => [503, 2];
        yield 'server error' => [500, 2];
        yield 'rate limited' => [429, 2];
        yield 'client error just under 500' => [499, 1];
        yield 'not found' => [404, 1];
    }

    /**
     * A failure that may pass later is tried again and can then succeed.
     *
     * @return void
     */
    public function testAServerErrorThenAnImageIsCopied(): void
    {
        $requests = $this->log();
        $client   = $this->recording($requests, static fn (string $method, string $url, array $options, int $count): MockResponse => 1 === $count
            ? new MockResponse('', ['http_code' => 503])
            : new MockResponse(self::PNG));

        $result = $this->importer($client)->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        self::assertCount(2, $requests);
        self::assertSame([], $result->failures);
        self::assertSame(1, $result->copied);
    }

    /**
     * Copied images, rejected files and images over the limit are downloaded only once.
     *
     * @return void
     */
    public function testFinalAnswersAreNotDownloadedAgain(): void
    {
        $requests = $this->log();
        $client   = $this->recording($requests, static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'page') => new MockResponse('<html>not an image</html>'),
            str_contains($url, 'large') => new MockResponse(str_repeat('x', 2048), ['response_headers' => ['content-length' => '2048']]),
            default => new MockResponse(self::PNG),
        });

        $result = $this->importer($client, ['rge_studio_connector_max_image_bytes' => 1024])->import(
            '<img src="https://cdn.example.org/a.png"><img src="https://cdn.example.org/a.png">'
            .'<img src="https://cdn.example.org/page.png"><img src="https://cdn.example.org/large.png">',
            microtime(true) + 30
        );

        self::assertCount(3, $requests);
        self::assertSame(1, $result->copied);
        self::assertEqualsCanonicalizing([
            ['url' => 'https://cdn.example.org/page.png', 'reason' => 'The file is not a PNG, JPEG, GIF or WebP image.'],
            ['url' => 'https://cdn.example.org/large.png', 'reason' => 'The image is larger than the limit.'],
        ], $result->failures);
    }

    /**
     * An image of exactly the limit is copied and one byte more is not.
     *
     * @return void
     */
    public function testSizeLimitIsExact(): void
    {
        $exact  = self::PNG.str_repeat("\0", 1024 - strlen(self::PNG));
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(str_contains($url, 'over') ? $exact."\0" : $exact));

        $result = $this->importer($client, ['rge_studio_connector_max_image_bytes' => 1024])->import(
            '<img src="https://cdn.example.org/exact.png"><img src="https://cdn.example.org/over.png">',
            microtime(true) + 30
        );

        self::assertSame(1, $result->copied);
        self::assertSame([['url' => 'https://cdn.example.org/over.png', 'reason' => 'The image is larger than the limit.']], $result->failures);
    }

    /**
     * Addresses outside the designer's storage are found in every quote style and srcset entry.
     *
     * @return void
     */
    public function testFindsImagesInEveryQuoteStyle(): void
    {
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(self::PNG.$url));
        $html   = '<img src="https://cdn.example.org/1.png"><img src=\'https://cdn.example.org/2.png\'><img src=https://cdn.example.org/3.png>'
            .'<img srcset="https://cdn.example.org/4.png 1x, https://cdn.example.org/5.png 2x"><img srcset=\'https://cdn.example.org/6.png 2x\'>'
            .'<img src=" https://cdn.example.org/7.png ">';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $copy = fn (int $number): string => 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG.'https://cdn.example.org/'.$number.'.png').'.png';
        self::assertSame(7, $result->copied);
        self::assertSame(
            '<img src="'.$copy(1).'"><img src=\''.$copy(2).'\'><img src='.$copy(3).'>'
            .'<img srcset="'.$copy(4).' 1x, '.$copy(5).' 2x"><img srcset=\''.$copy(6).' 2x\'>'
            .'<img src=" '.$copy(7).' ">',
            $result->html
        );
    }

    /**
     * An address that starts like another one is replaced by its own copy.
     *
     * @return void
     */
    public function testLongerAddressesAreReplacedFirst(): void
    {
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(self::PNG.$url));
        $result = $this->importer($client)->import(
            '<img src="https://cdn.example.org/a.png"><img src="https://cdn.example.org/a.png?w=2">',
            microtime(true) + 30
        );

        $copy = fn (string $url): string => 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG.$url).'.png';
        self::assertSame('<img src="'.$copy('https://cdn.example.org/a.png').'"><img src="'.$copy('https://cdn.example.org/a.png?w=2').'">', $result->html);
    }

    /**
     * A personalised address that starts with the address of a plain image stays exactly as designed, whether the
     * plain image is copied or not.
     *
     * @return void
     */
    public function testPersonalisedAddressStartingWithAPlainOneIsKept(): void
    {
        $personalised = '<img src="https://images.example.org/hello.png?name={contactfield=firstname}">'
            .'<img srcset="https://images.example.org/hello.png?n=%7Bcontactfield%7D 2x">';
        $html = '<img src="https://images.example.org/hello.png">'.$personalised;

        $copied = $this->importer(new MockHttpClient(new MockResponse(self::PNG)))->import($html, microtime(true) + 30);
        $failed = $this->importer(new MockHttpClient(new MockResponse('', ['http_code' => 404])))->import($html, microtime(true) + 30);

        $copy = 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG).'.png';
        self::assertSame('<img src="'.$copy.'">'.$personalised, $copied->html);
        self::assertSame('<img src="">'.$personalised, $failed->html);
    }

    /**
     * Hosts the private network guard cannot check before connecting are never downloaded and stay as designed,
     * while IP addresses and ordinary names are.
     *
     * @return void
     */
    public function testHostsTheGuardCannotCheckAreNeverDownloaded(): void
    {
        $requests = $this->log();
        $client   = $this->recording($requests, static fn (): MockResponse => new MockResponse(self::PNG));
        $skipped  = '<img src="http://localhost/a.png"><img src="http://2130706433/a.png"><img src="http://127.1/a.png">'
            .'<img src="http://0x7f.1/a.png"><img src="http://intranet/a.png"><img src="http://0/a.png">'
            .'<img src="http://cdn.example.org.123/a.png"><img src="http://.example.org/a.png">';
        $html = $skipped.'<img src="http://203.0.113.7/a.png"><img src="http://[2001:db8::7]/a.png"><img src="https://img_1.cdn-2.example.org./a.png">';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $urls = array_map(static fn (array $request): string => $request['url'], $requests->getArrayCopy());
        self::assertSame(['http://203.0.113.7/a.png', 'http://[2001:db8::7]/a.png', 'https://img_1.cdn-2.example.org./a.png'], $urls);
        self::assertStringStartsWith($skipped, $result->html);
        self::assertSame(3, $result->copied);
    }

    /**
     * An address is replaced only where it was found as an image, never inside a link or another address that holds
     * it, whether it was copied or not.
     *
     * @return void
     */
    public function testAddressIsReplacedOnlyWhereItWasFound(): void
    {
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_contains($url, 'shop')
            ? new MockResponse('<html>a shop</html>')
            : new MockResponse(self::PNG));
        $html = '<img src="//cdn.example.org/a.png"><a href="https://cdn.example.org/a.png">Large</a>'
            .'<img src="https://shop.example.com"><a href="https://shop.example.com/offer">Offer</a>';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $copy = 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG).'.png';
        self::assertSame(
            '<img src="'.$copy.'"><a href="https://cdn.example.org/a.png">Large</a>'
            .'<img src=""><a href="https://shop.example.com/offer">Offer</a>',
            $result->html
        );
        self::assertSame([['url' => 'https://shop.example.com', 'reason' => 'The file is not a PNG, JPEG, GIF or WebP image.']], $result->failures);
    }

    /**
     * Plain images before and after personalised addresses are all replaced, and when the patterns cannot run, as
     * when PCRE hits its backtracking limit, the HTML is kept as it is and flagged.
     *
     * @return void
     */
    public function testImagesAroundPersonalisedAddressesAreReplaced(): void
    {
        $html = '<img src="https://cdn.example.org/a.png"><img src="https://cdn.example.org/a.png?n={contactfield=firstname}">'
            .'<img src="https://cdn.example.org/a.png"><img src="https://cdn.example.org/a.png?n={contactfield=lastname}">'
            .'<img src="https://cdn.example.org/a.png">';
        $copy = 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG).'.png';

        $result = $this->importer(new MockHttpClient(new MockResponse(self::PNG)))->import($html, microtime(true) + 30);

        self::assertSame(str_replace('"https://cdn.example.org/a.png"', '"'.$copy.'"', $html), $result->html);

        $jit       = ini_get('pcre.jit');
        $backtrack = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $failed = $this->importer(new MockHttpClient(new MockResponse(self::PNG)))->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);
        } finally {
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
            ini_set('pcre.backtrack_limit', false === $backtrack ? '1000000' : $backtrack);
        }

        self::assertSame('<img src="https://cdn.example.org/a.png">', $failed->html);
        self::assertSame([['url' => 'HTML', 'reason' => 'It could not be searched for images, so some may not be copied.']], $failed->failures);
    }

    /**
     * A quoted CSS address may hold parentheses, in plain or entity quotes, and an unquoted one still works.
     *
     * @return void
     */
    public function testQuotedCssAddressesMayHoldParentheses(): void
    {
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(self::PNG.$url));
        $html   = '<td style="background:url(\'https://cdn.example.org/hero(1).png\')">'
            .'<td style="background:url( &quot;https://cdn.example.org/hero(2).png&quot; )">'
            .'<td style="background:url(https://cdn.example.org/3.png)">';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $copy = fn (string $url): string => 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG.$url).'.png';
        self::assertSame(3, $result->copied);
        self::assertSame(
            '<td style="background:url(\''.$copy('https://cdn.example.org/hero(1).png').'\')">'
            .'<td style="background:url( &quot;'.$copy('https://cdn.example.org/hero(2).png').'&quot; )">'
            .'<td style="background:url('.$copy('https://cdn.example.org/3.png').')">',
            $result->html
        );
    }

    /**
     * A designer storage address holding parentheses is found whole and never also as the part before them, and an
     * unquoted CSS address still ends at its closing parenthesis.
     *
     * @return void
     */
    public function testDesignerAddressesMayHoldParentheses(): void
    {
        $requests = $this->log();
        $client   = $this->recording($requests, static fn (string $method, string $url): MockResponse => new MockResponse(self::PNG.$url));
        $html     = '<td style="background:url(\'https://media.beefree.cloud/hero(1).png\')">'
            .'<img src="https://media.beefree.cloud/a(b)c.png">'
            .'<p>https://media.beefree.cloud/x(2).png</p>'
            .'<td style="background:url(https://media.beefree.cloud/plain.png)">';

        $result = $this->importer($client)->import($html, microtime(true) + 30);

        $urls = array_map(static fn (array $request): string => $request['url'], $requests->getArrayCopy());
        sort($urls);
        self::assertSame([
            'https://media.beefree.cloud/a(b)c.png',
            'https://media.beefree.cloud/hero(1).png',
            'https://media.beefree.cloud/plain.png',
            'https://media.beefree.cloud/x(2).png',
        ], $urls);
        self::assertSame(4, $result->copied);
        self::assertSame([], $result->failures);
    }

    /**
     * Two spellings of one address are downloaded once and both point to the copy.
     *
     * @return void
     */
    public function testTwoSpellingsOfOneAddressAreDownloadedOnce(): void
    {
        $requests = $this->log();
        $result   = $this->importer($this->recording($requests, static fn (): MockResponse => new MockResponse(self::PNG)))
            ->import('<img src="//cdn.example.org/a.png"><img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        $copy = 'https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG).'.png';
        self::assertCount(1, $requests);
        self::assertSame('<img src="'.$copy.'"><img src="'.$copy.'">', $result->html);
    }

    /**
     * Images over the per-export limit are not downloaded.
     *
     * @return void
     */
    public function testImagesOverThePerExportLimit(): void
    {
        $requests = $this->log();
        $result   = $this->importer($this->recording($requests, static fn (): MockResponse => new MockResponse(self::PNG)), ['rge_studio_connector_max_images' => 1])
            ->import('<img src="https://cdn.example.org/a.png"><img src="https://cdn.example.org/b.png">', microtime(true) + 30);

        self::assertCount(1, $requests);
        self::assertSame([['url' => 'https://cdn.example.org/b.png', 'reason' => 'Too many images in one export.']], $result->failures);
    }

    /**
     * Every designer host left in the HTML is reported, and only real designer hosts count.
     *
     * @return void
     */
    public function testLeftoverDesignerAddressesAreReported(): void
    {
        $requests = $this->log();
        $result   = $this->importer($this->recording($requests, static fn (): MockResponse => new MockResponse(self::PNG)))
            ->import('<p>See media.beefree.cloud and imgdist.com, not https://mediaxbeefree.cloud/a.png</p>', microtime(true) + 30);

        self::assertCount(0, $requests);
        self::assertSame([
            ['url' => 'media.beefree.cloud', 'reason' => 'An address on the designer\'s storage is still in the HTML.'],
            ['url' => 'imgdist.com', 'reason' => 'An address on the designer\'s storage is still in the HTML.'],
        ], $result->failures);
    }

    /**
     * Addresses are read without regard to case, and only Mautic's own address counts as Mautic's.
     *
     * @return void
     */
    public function testAddressesAreReadWithoutRegardToCase(): void
    {
        $requests = $this->log();
        $this->importer($this->recording($requests, static fn (): MockResponse => new MockResponse(self::PNG)))->import(
            '<img src="HTTPS://CDN.EXAMPLE.ORG/A.PNG"><img src="https://cdn.example.org/b.png?n=%7bcontactfield%7d">'
            .'<img src="https://mautic.example.com.example.org/c.png"><img src="https://mautic.example.com/media/images/d.png">'
            .'<img src="https://cdn.example.org/e.png&#32;"><img src="https://cdn.example.org/f.png?a=1&amp;b=&apos;2&apos;">',
            microtime(true) + 30
        );

        self::assertSame([
            'https://cdn.example.org/A.PNG',
            'https://mautic.example.com.example.org/c.png',
            'https://cdn.example.org/e.png',
            "https://cdn.example.org/f.png?a=1&b='2'",
        ], array_column($requests->getArrayCopy(), 'url'));
    }

    /**
     * PNG, JPEG, both GIF versions and WebP are kept with their own extension, other RIFF files are not.
     *
     * @return void
     */
    public function testImageFormats(): void
    {
        $files = [
            'png'  => self::PNG,
            'jpg'  => "\xFF\xD8\xFF\xE0".str_repeat("\0", 12),
            'gif'  => 'GIF87a'.str_repeat("\0", 10),
            'gif2' => 'GIF89a'.str_repeat("\1", 10),
            'webp' => "RIFF\x24\x00\x00\x00WEBPVP8 ",
            'wave' => "RIFF\x24\x00\x00\x00WAVEfmt ",
        ];
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse($files[basename($url)]));

        $result = $this->importer($client)->import(implode('', array_map(static fn (string $name): string => '<img src="https://cdn.example.org/'.$name.'">', array_keys($files))), microtime(true) + 30);

        foreach (['png', 'jpg', 'gif', 'webp'] as $extension) {
            self::assertStringContainsString(hash('sha256', $files[$extension]).'.'.$extension, $result->html);
        }
        self::assertStringContainsString(hash('sha256', $files['gif2']).'.gif', $result->html);
        self::assertSame([['url' => 'https://cdn.example.org/wave', 'reason' => 'The file is not a PNG, JPEG, GIF or WebP image.']], $result->failures);
    }

    /**
     * Copies can be read by the web server, and the image folder is created when missing.
     *
     * @return void
     */
    public function testCopiesCanBeReadByTheWebServer(): void
    {
        $previous = umask(0);
        try {
            $this->importer(new MockHttpClient(new MockResponse(self::PNG)))->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);
        } finally {
            umask($previous);
        }

        $folder = $this->root.'/media/images/rge-studio';
        self::assertSame(0775, fileperms($folder) & 0777);
        self::assertSame(0644, fileperms($folder.'/'.hash('sha256', self::PNG).'.png') & 0777);
    }

    /**
     * The public address joins Mautic's address and the images path with single slashes.
     *
     * @return void
     */
    public function testPublicAddressHasSingleSlashes(): void
    {
        $result = $this->importer(new MockHttpClient(new MockResponse(self::PNG)), [], '/media/images/')
            ->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        self::assertSame('<img src="https://mautic.example.com/media/images/rge-studio/'.hash('sha256', self::PNG).'.png">', $result->html);
    }

    /**
     * Nothing is downloaded once the time is up.
     *
     * @return void
     */
    public function testNothingStartsAfterTheDeadline(): void
    {
        $requests = $this->log();
        $result   = $this->importer($this->recording($requests, static fn (): MockResponse => new MockResponse(self::PNG)))
            ->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 0.2);

        self::assertCount(0, $requests);
        self::assertSame([['url' => 'https://cdn.example.org/a.png', 'reason' => 'Not copied in time.']], $result->failures);
    }

    /**
     * Network failures are tried again with their own reason.
     *
     * @return void
     */
    public function testNetworkFailuresAreTriedAgain(): void
    {
        $requests = $this->log();
        $result   = $this->importer($this->recording($requests, static fn (): MockResponse => new MockResponse('', ['error' => 'connection reset'])))
            ->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        self::assertCount(2, $requests);
        self::assertSame([['url' => 'https://cdn.example.org/a.png', 'reason' => 'The download failed.']], $result->failures);
    }

    /**
     * A download that cannot even start is tried again with its own reason.
     *
     * @return void
     */
    public function testDownloadsThatCannotStartAreTriedAgain(): void
    {
        $requests = $this->log();
        $result   = $this->importer($this->recording($requests, static function (): MockResponse {
            throw new TransportException('no route');
        }))->import('<img src="https://cdn.example.org/a.png">', microtime(true) + 30);

        self::assertCount(2, $requests);
        self::assertSame([['url' => 'https://cdn.example.org/a.png', 'reason' => 'The download could not start.']], $result->failures);
    }

    /**
     * Empty list of requests for a recording client to fill in.
     *
     * @return \ArrayObject<int, array{method: string, url: string, options: array<mixed>}>
     */
    private function log(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * Client that records every request before answering it.
     *
     * @param \ArrayObject<int, array{method: string, url: string, options: array<mixed>}> $requests Requests made, filled in by the client
     * @param callable(string, string, array<mixed>, int): MockResponse                    $answer   Builds the answer from method, address, options and the request number
     *
     * @return MockHttpClient
     */
    private function recording(\ArrayObject $requests, callable $answer): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use ($requests, $answer): MockResponse {
            $requests->append(['method' => $method, 'url' => $url, 'options' => $options]);

            return $answer($method, $url, $options, count($requests));
        });
    }

    /**
     * Importer writing under the temporary root.
     *
     * @param HttpClientInterface  $client     HTTP client that answers the downloads
     * @param array<string, mixed> $parameters Settings to change
     * @param string               $relative   Images path as Mautic gives it
     *
     * @return ImageImporter
     */
    private function importer(HttpClientInterface $client, array $parameters = [], string $relative = 'media/images'): ImageImporter
    {
        $paths = new class($this->root, $relative) extends PathsHelper {
            /**
             * Build the stub.
             *
             * @param string $root     Folder that stands in for Mautic's root
             * @param string $relative Images path relative to the root
             */
            public function __construct(private string $root, private string $relative)
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
            public function getSystemPath($name, $fullPath = false): string
            {
                return $fullPath ? $this->root.'/media/images' : $this->relative;
            }
        };

        return new ImageImporter($client, $paths, SettingsStub::create($parameters), new ImageFiles($this->logger));
    }
}
