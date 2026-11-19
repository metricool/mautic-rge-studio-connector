<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use MauticPlugin\RgeStudioConnectorBundle\Import\ImageDownloadBatch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ImageDownloadBatchTest extends TestCase
{
    private string $directory;

    /**
     * Create an empty folder for the temporary files.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/rge-studio-batch-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    /**
     * Remove the folder and every file written in it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * A failure records the retry flag only when another attempt may succeed.
     *
     * @return void
     */
    public function testFailuresRecordTheRetryFlagOnlyWhenAsked(): void
    {
        $batch = new ImageDownloadBatch($this->directory, 1024);
        $batch->fail('https://cdn.example.org/a.png', 'Try later.', true);
        $batch->fail('https://cdn.example.org/b.png', 'Give up.', false);

        self::assertSame([
            'https://cdn.example.org/a.png' => ['error' => 'Try later.', 'retry' => true],
            'https://cdn.example.org/b.png' => ['error' => 'Give up.'],
        ], $batch->outcomes());
        self::assertFalse($batch->isRunning());
    }

    /**
     * Running downloads are handed to the stream as a plain list.
     *
     * @return void
     */
    public function testRunningDownloadsAreAList(): void
    {
        $batch = new ImageDownloadBatch($this->directory, 1024);
        $first = $this->response('https://cdn.example.org/a.png');
        $other = $this->response('https://cdn.example.org/b.png');
        $batch->add('https://cdn.example.org/a.png', $first);
        $batch->add('https://cdn.example.org/b.png', $other);

        self::assertTrue($batch->isRunning());
        self::assertSame([$first, $other], $batch->responses());
    }

    /**
     * A timeout before the deadline keeps waiting, one after it stops the stream.
     *
     * @return void
     */
    public function testTimeoutsStopTheStreamOnlyAfterTheDeadline(): void
    {
        $batch    = new ImageDownloadBatch($this->directory, 1024);
        $response = $this->response('https://cdn.example.org/a.png');
        $batch->add('https://cdn.example.org/a.png', $response);

        self::assertFalse($batch->receive($response, new ErrorChunk(0, 'idle'), microtime(true) + 30));
        self::assertTrue($batch->receive($response, new ErrorChunk(0, 'idle'), microtime(true) - 1));
        self::assertTrue($batch->isRunning());
        self::assertSame([], $batch->outcomes());
    }

    /**
     * A chunk of a download the batch no longer runs is ignored.
     *
     * @return void
     */
    public function testChunksOfUnknownDownloadsAreIgnored(): void
    {
        $batch = new ImageDownloadBatch($this->directory, 1024);

        self::assertFalse($batch->receive($this->response('https://cdn.example.org/a.png'), new FirstChunk(), microtime(true) + 30));
        self::assertSame([], $batch->outcomes());
        self::assertSame([], glob($this->directory.'/.rge*') ?: []);
    }

    /**
     * Downloads still running are cancelled and reported as not copied in time.
     *
     * @return void
     */
    public function testRemainingDownloadsAreCancelled(): void
    {
        $batch    = new ImageDownloadBatch($this->directory, 1024);
        $response = $this->response('https://cdn.example.org/a.png');
        $other    = $this->response('https://cdn.example.org/b.png');
        $batch->add('https://cdn.example.org/a.png', $response);
        $batch->add('https://cdn.example.org/b.png', $other);

        $batch->cancelRemaining();

        self::assertFalse($batch->isRunning());
        self::assertTrue($response->getInfo('canceled'));
        self::assertTrue($other->getInfo('canceled'));
        self::assertSame([
            'https://cdn.example.org/a.png' => ['error' => 'Not copied in time.'],
            'https://cdn.example.org/b.png' => ['error' => 'Not copied in time.'],
        ], $batch->outcomes());
    }

    /**
     * A complete download keeps its temporary file and ends the download.
     *
     * @return void
     */
    public function testCompleteDownloadKeepsItsFile(): void
    {
        $batch    = new ImageDownloadBatch($this->directory, 1024);
        $response = $this->response('https://cdn.example.org/a.png');
        $batch->add('https://cdn.example.org/a.png', $response);

        self::assertFalse($batch->receive($response, new FirstChunk(), microtime(true) + 30));
        self::assertFalse($batch->receive($response, new DataChunk(0, 'abc'), microtime(true) + 30));
        self::assertFalse($batch->receive($response, new DataChunk(3, 'def'), microtime(true) + 30));
        self::assertFalse($batch->receive($response, new LastChunk(6), microtime(true) + 30));

        $outcomes = $batch->outcomes();
        self::assertFalse($batch->isRunning());
        self::assertSame(['path'], array_keys($outcomes['https://cdn.example.org/a.png']));
        self::assertSame('abcdef', file_get_contents($outcomes['https://cdn.example.org/a.png']['path'] ?? ''));
    }

    /**
     * Bytes over the limit stop the download and remove its temporary file.
     *
     * @return void
     */
    public function testBytesOverTheLimitStopTheDownload(): void
    {
        $batch    = new ImageDownloadBatch($this->directory, 4);
        $response = $this->response('https://cdn.example.org/a.png');
        $batch->add('https://cdn.example.org/a.png', $response);

        $batch->receive($response, new FirstChunk(), microtime(true) + 30);
        $batch->receive($response, new DataChunk(0, 'abcd'), microtime(true) + 30);
        self::assertTrue($batch->isRunning());
        $batch->receive($response, new DataChunk(4, 'e'), microtime(true) + 30);

        self::assertFalse($batch->isRunning());
        self::assertTrue($response->getInfo('canceled'));
        self::assertSame(['https://cdn.example.org/a.png' => ['error' => 'The image is larger than the limit.']], $batch->outcomes());
        self::assertSame([], glob($this->directory.'/.rge*') ?: []);
    }

    /**
     * A write that fails, as on a full disk, stops the download and keeps no partial file.
     *
     * @return void
     */
    public function testFailedWriteStopsTheDownload(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('This system has no /dev/full to fill.');
        }

        $batch    = new ImageDownloadBatch($this->directory, 1024);
        $response = $this->response('https://cdn.example.org/a.png');
        $batch->add('https://cdn.example.org/a.png', $response);
        $batch->receive($response, new FirstChunk(), microtime(true) + 30);

        $files = new \ReflectionProperty(ImageDownloadBatch::class, 'files');
        $open  = $files->getValue($batch);
        self::assertIsArray($open);
        self::assertIsArray($open['https://cdn.example.org/a.png']);
        $open['https://cdn.example.org/a.png']['handle'] = fopen('/dev/full', 'wb');
        $files->setValue($batch, $open);

        self::assertFalse($batch->receive($response, new DataChunk(0, 'abc'), microtime(true) + 30));

        self::assertFalse($batch->isRunning());
        self::assertTrue($response->getInfo('canceled'));
        self::assertSame(['https://cdn.example.org/a.png' => ['error' => 'The image could not be written.']], $batch->outcomes());
        self::assertSame([], glob($this->directory.'/.rge*') ?: []);
    }

    /**
     * A network failure in the middle of a download is recorded as worth another attempt.
     *
     * @return void
     */
    public function testNetworkFailuresAreWorthAnotherAttempt(): void
    {
        $batch    = new ImageDownloadBatch($this->directory, 1024);
        $response = $this->response('https://cdn.example.org/a.png');
        $batch->add('https://cdn.example.org/a.png', $response);

        $batch->receive($response, new FirstChunk(), microtime(true) + 30);
        $batch->receive($response, new ErrorChunk(0, new \RuntimeException('connection reset')), microtime(true) + 30);

        self::assertTrue($response->getInfo('canceled'));
        self::assertSame(['https://cdn.example.org/a.png' => ['error' => 'The download failed.', 'retry' => true]], $batch->outcomes());
        self::assertSame([], glob($this->directory.'/.rge*') ?: []);
    }

    /**
     * A declared length over the limit stops the download before any byte is read, one at the limit goes on.
     *
     * @return void
     */
    public function testDeclaredLengthIsCheckedFirst(): void
    {
        $batch = new ImageDownloadBatch($this->directory, 1024);
        $over  = $this->declared('https://cdn.example.org/over.png', '1025');
        $limit = $this->declared('https://cdn.example.org/limit.png', '1024');
        $batch->add('https://cdn.example.org/over.png', $over);
        $batch->add('https://cdn.example.org/limit.png', $limit);

        $batch->receive($over, new FirstChunk(), microtime(true) + 30);
        $batch->receive($limit, new FirstChunk(), microtime(true) + 30);

        self::assertTrue($over->getInfo('canceled'));
        self::assertFalse($limit->getInfo('canceled'));
        self::assertSame([$limit], $batch->responses());
        self::assertSame(['https://cdn.example.org/over.png' => ['error' => 'The image is larger than the limit.']], $batch->outcomes());
    }

    /**
     * Download answering 200 with a declared length, and remembering whether it was cancelled.
     *
     * @param string $url    Address of the image
     * @param string $length Declared content length
     *
     * @return ResponseInterface
     */
    private function declared(string $url, string $length): ResponseInterface
    {
        return new class($url, $length) implements ResponseInterface {
            private bool $canceled = false;

            /**
             * Build the response.
             *
             * @param string $url    Address of the image
             * @param string $length Declared content length
             */
            public function __construct(private string $url, private string $length)
            {
            }

            /**
             * Status of the answer.
             *
             * @return int
             */
            public function getStatusCode(): int
            {
                return 200;
            }

            /**
             * Headers of the answer.
             *
             * @param bool $throw Whether to throw on error statuses
             *
             * @return array<string, list<string>>
             */
            public function getHeaders(bool $throw = true): array
            {
                return ['content-length' => [$this->length]];
            }

            /**
             * Body of the answer.
             *
             * @param bool $throw Whether to throw on error statuses
             *
             * @return string
             */
            public function getContent(bool $throw = true): string
            {
                return '';
            }

            /**
             * Body decoded as JSON.
             *
             * @param bool $throw Whether to throw on error statuses
             *
             * @return array<mixed>
             */
            public function toArray(bool $throw = true): array
            {
                return [];
            }

            /**
             * Remember that the download was cancelled.
             *
             * @return void
             */
            public function cancel(): void
            {
                $this->canceled = true;
            }

            /**
             * Information about the download.
             *
             * @param string|null $type Item asked for, or null for all
             *
             * @return mixed
             */
            public function getInfo(?string $type = null): mixed
            {
                $info = ['user_data' => $this->url, 'canceled' => $this->canceled];

                return null === $type ? $info : ($info[$type] ?? null);
            }
        };
    }

    /**
     * Download started by a mock client, answering 200 with an empty body.
     *
     * @param string $url Address of the image
     *
     * @return ResponseInterface
     */
    private function response(string $url): ResponseInterface
    {
        return (new MockHttpClient(new MockResponse('')))->request('GET', $url, ['user_data' => $url]);
    }
}
