<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Psr\Log\LoggerInterface;
use Symfony\Component\ErrorHandler\ErrorHandler;

class ImageFiles
{
    /**
     * Build the file operations of the image folder.
     *
     * @param LoggerInterface $logger Mautic logger, which receives the PHP error of every failed operation
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Create a folder and its parents when missing.
     *
     * @param string $directory Folder to create
     *
     * @return void
     */
    public function createFolder(string $directory): void
    {
        if (!is_dir($directory)) {
            $this->attempt(static fn (): bool => mkdir($directory, 0775, true), 'the image folder could not be created.', ['path' => $directory]);
        }
    }

    /**
     * Remove a temporary file.
     *
     * @param string $path File to remove
     *
     * @return void
     */
    public function remove(string $path): void
    {
        $this->attempt(static fn (): bool => unlink($path), 'a temporary image file could not be removed.', ['path' => $path]);
    }

    /**
     * Give a file its final name.
     *
     * @param string $from File to move
     * @param string $to   Final name
     *
     * @return bool Whether the file has its final name
     */
    public function move(string $from, string $to): bool
    {
        return $this->attempt(static fn (): bool => rename($from, $to), 'an image could not be saved in the media folder.', ['from' => $from, 'to' => $to]);
    }

    /**
     * Let every user of the server read a saved image, the web server included.
     *
     * @param string $path Saved image
     *
     * @return void
     */
    public function makeReadable(string $path): void
    {
        $this->attempt(static fn (): bool => chmod($path, 0644), 'a saved image could not be made readable.', ['path' => $path]);
    }

    /**
     * Append bytes to an open file.
     *
     * @param resource $handle  Open file
     * @param string   $content Bytes to append
     *
     * @return bool Whether every byte was written
     */
    public function write(mixed $handle, string $content): bool
    {
        return $this->attempt(static fn (): bool => strlen($content) === fwrite($handle, $content), 'a downloaded image could not be written.', ['bytes' => strlen($content)]);
    }

    /**
     * Run a file operation, turning its PHP error into a log entry instead of letting it pass unseen.
     *
     * @param callable(): bool     $operation Operation that tells whether it succeeded
     * @param string               $failure   End of the log message when it fails
     * @param array<string, mixed> $context   Log context
     *
     * @return bool Whether the operation succeeded
     */
    private function attempt(callable $operation, string $failure, array $context): bool
    {
        try {
            if (true === ErrorHandler::call($operation)) {
                return true;
            }
            $error = 'The operation failed without a PHP error.';
        } catch (\ErrorException $exception) {
            $error = $exception->getMessage();
        }

        $this->logger->warning('RGE Studio connector: '.$failure, $context + ['error' => $error]);

        return false;
    }
}
