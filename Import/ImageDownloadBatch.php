<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ImageDownloadBatch
{
    /**
     * @var array<string, ResponseInterface>
     */
    private array $active = [];

    /**
     * @var array<string, array{path: string, handle: resource}>
     */
    private array $files = [];

    /**
     * @var array<string, int>
     */
    private array $sizes = [];

    /**
     * @var array<string, array{path?: string, error?: string, retry?: bool}>
     */
    private array $outcomes = [];

    /**
     * Build an empty batch.
     *
     * @param string $directory Folder that receives the temporary files
     * @param int    $maxBytes  Largest image accepted, in bytes
     */
    public function __construct(
        private string $directory,
        private int $maxBytes,
    ) {
    }

    /**
     * Track a download that has started.
     *
     * @param string            $url      Address of the image
     * @param ResponseInterface $response Download
     *
     * @return void
     */
    public function add(string $url, ResponseInterface $response): void
    {
        $this->active[$url] = $response;
    }

    /**
     * Record an image that failed without a running download.
     *
     * @param string $url   Address of the image
     * @param string $error Reason shown to the designer
     * @param bool   $retry Whether another attempt may succeed
     *
     * @return void
     */
    public function fail(string $url, string $error, bool $retry): void
    {
        $this->outcomes[$url] = $retry ? ['error' => $error, 'retry' => true] : ['error' => $error];
    }

    /**
     * Whether any download is still running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return [] !== $this->active;
    }

    /**
     * Downloads still running.
     *
     * @return list<ResponseInterface>
     */
    public function responses(): array
    {
        return array_values($this->active);
    }

    /**
     * Handle one chunk of one download.
     *
     * @param ResponseInterface $response Download the chunk belongs to
     * @param ChunkInterface    $chunk    Chunk received
     * @param float             $deadline Unix time with microseconds when downloads must stop
     *
     * @return bool Whether the deadline has passed and streaming must stop
     */
    public function receive(ResponseInterface $response, ChunkInterface $chunk, float $deadline): bool
    {
        $url = $this->urlOf($response);
        if (!isset($this->active[$url])) {
            return false;
        }

        try {
            if ($chunk->isTimeout()) {
                return microtime(true) >= $deadline;
            }
            if ($chunk->isFirst() && !$this->open($url, $response)) {
                return false;
            }
            if ($this->write($url, $chunk->getContent()) && $chunk->isLast()) {
                $this->finish($url);
            }
        } catch (ExceptionInterface) {
            $this->stop($url, 'The download failed.', true);
        }

        return false;
    }

    /**
     * Cancel every download still running, as not copied in time.
     *
     * @return void
     */
    public function cancelRemaining(): void
    {
        foreach (array_keys($this->active) as $url) {
            $this->stop($url, 'Not copied in time.', false);
        }
    }

    /**
     * Outcome of every image: the downloaded temporary file, or the reason it failed.
     *
     * @return array<string, array{path?: string, error?: string, retry?: bool}>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /**
     * Check the answer of a download and open its temporary file.
     *
     * @param string            $url      Address of the image
     * @param ResponseInterface $response Download
     *
     * @return bool Whether the download goes on
     */
    private function open(string $url, ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();
        if (200 !== $status) {
            $this->stop($url, 'The image server answered '.$status.'.', $status >= 500 || 429 === $status);

            return false;
        }

        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if (is_numeric($length) && $length > $this->maxBytes) {
            $this->stop($url, 'The image is larger than the limit.', false);

            return false;
        }

        $path = tempnam($this->directory, '.rge');
        $file = false === $path ? false : fopen($path, 'wb');
        if (false === $path || false === $file) {
            $this->stop($url, 'The image folder is not writable.', false);

            return false;
        }

        $this->files[$url] = ['path' => $path, 'handle' => $file];
        $this->sizes[$url] = 0;

        return true;
    }

    /**
     * Append received bytes to the temporary file, within the size limit.
     *
     * @param string $url     Address of the image
     * @param string $content Bytes received
     *
     * @return bool Whether the download goes on
     */
    private function write(string $url, string $content): bool
    {
        if (!isset($this->files[$url])) {
            return false;
        }

        $this->sizes[$url] += strlen($content);
        if ($this->sizes[$url] > $this->maxBytes) {
            $this->stop($url, 'The image is larger than the limit.', false);

            return false;
        }

        if ('' !== $content && strlen($content) !== @fwrite($this->files[$url]['handle'], $content)) {
            $this->stop($url, 'The image could not be written.', false);

            return false;
        }

        return true;
    }

    /**
     * Close a complete download and keep its temporary file.
     *
     * @param string $url Address of the image
     *
     * @return void
     */
    private function finish(string $url): void
    {
        fclose($this->files[$url]['handle']);
        $this->outcomes[$url] = ['path' => $this->files[$url]['path']];
        unset($this->files[$url], $this->active[$url]);
    }

    /**
     * Cancel a download, drop its temporary file and record why.
     *
     * @param string $url   Address of the image
     * @param string $error Reason shown to the designer
     * @param bool   $retry Whether another attempt may succeed
     *
     * @return void
     */
    private function stop(string $url, string $error, bool $retry): void
    {
        if (isset($this->active[$url])) {
            $this->active[$url]->cancel();
            unset($this->active[$url]);
        }

        if (isset($this->files[$url])) {
            if (is_resource($this->files[$url]['handle'])) {
                fclose($this->files[$url]['handle']);
            }
            @unlink($this->files[$url]['path']);
            unset($this->files[$url]);
        }

        $this->fail($url, $error, $retry);
    }

    /**
     * Address a download was started for.
     *
     * @param ResponseInterface $response Download
     *
     * @return string
     */
    private function urlOf(ResponseInterface $response): string
    {
        $url = $response->getInfo('user_data');

        return is_string($url) ? $url : '';
    }
}
