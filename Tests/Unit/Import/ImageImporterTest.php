<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImageImporter;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
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
     * Plain images before and after personalised addresses are all replaced, and when the patterns cannot run, as
     * when PCRE hits its backtracking limit, the HTML is kept as it is.
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
     * @param MockHttpClient       $client     HTTP client that answers the downloads
     * @param array<string, mixed> $parameters Settings to change
     * @param string               $relative   Images path as Mautic gives it
     *
     * @return ImageImporter
     */
    private function importer(MockHttpClient $client, array $parameters = [], string $relative = 'media/images'): ImageImporter
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

        return new ImageImporter($client, $paths, SettingsStub::create($parameters));
    }
}
