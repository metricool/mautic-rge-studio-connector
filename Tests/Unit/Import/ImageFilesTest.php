<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use MauticPlugin\RgeStudioConnectorBundle\Import\ImageFiles;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SpyLogger;
use PHPUnit\Framework\TestCase;

final class ImageFilesTest extends TestCase
{
    private string $directory;

    private SpyLogger $logger;

    private ImageFiles $files;

    /**
     * Create an empty folder to work in, and file operations that log to a logger to read.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/rge-studio-files-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->logger = new SpyLogger();
        $this->files  = new ImageFiles($this->logger);
    }

    /**
     * Remove the folder and everything written in it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    /**
     * A missing folder is created with its parents, an existing one is left alone, and one that cannot be created
     * is logged with its PHP error.
     *
     * @return void
     */
    public function testFoldersAreCreatedWhenMissing(): void
    {
        $this->files->createFolder($this->directory.'/a/b');
        $this->files->createFolder($this->directory.'/a/b');

        self::assertDirectoryExists($this->directory.'/a/b');
        self::assertSame([], $this->logger->records);

        touch($this->directory.'/file');
        $this->files->createFolder($this->directory.'/file/c');

        $this->assertLogged('the image folder could not be created.', ['path' => $this->directory.'/file/c'], 'mkdir(): ');
    }

    /**
     * A file is removed, and one that cannot be removed is logged with its PHP error.
     *
     * @return void
     */
    public function testRemoveLogsAFileThatStays(): void
    {
        touch($this->directory.'/a');
        $this->files->remove($this->directory.'/a');

        self::assertFileDoesNotExist($this->directory.'/a');
        self::assertSame([], $this->logger->records);

        $this->files->remove($this->directory.'/missing');

        $this->assertLogged('a temporary image file could not be removed.', ['path' => $this->directory.'/missing'], 'unlink(');
    }

    /**
     * A file takes its final name, and a name that a folder holds is logged with its PHP error while the file stays.
     *
     * @return void
     */
    public function testMoveLogsAFailedSave(): void
    {
        file_put_contents($this->directory.'/a', 'a');
        self::assertTrue($this->files->move($this->directory.'/a', $this->directory.'/b'));

        self::assertFileExists($this->directory.'/b');
        self::assertSame([], $this->logger->records);

        mkdir($this->directory.'/taken');
        file_put_contents($this->directory.'/c', 'c');
        self::assertFalse($this->files->move($this->directory.'/c', $this->directory.'/taken'));

        self::assertFileExists($this->directory.'/c');
        $this->assertLogged('an image could not be saved in the media folder.', ['from' => $this->directory.'/c', 'to' => $this->directory.'/taken'], 'rename(');
    }

    /**
     * A saved image becomes readable by everyone, and a missing one is logged with its PHP error.
     *
     * @return void
     */
    public function testMakeReadableLogsAMissingImage(): void
    {
        touch($this->directory.'/a');
        chmod($this->directory.'/a', 0600);
        $this->files->makeReadable($this->directory.'/a');
        clearstatcache();

        self::assertSame(0644, fileperms($this->directory.'/a') & 0777);
        self::assertSame([], $this->logger->records);

        $this->files->makeReadable($this->directory.'/missing');

        $this->assertLogged('a saved image could not be made readable.', ['path' => $this->directory.'/missing'], 'chmod(): ');
    }

    /**
     * Bytes are appended to an open file, and a write that fails is logged with its PHP error.
     *
     * @return void
     */
    public function testWriteLogsAFailedWrite(): void
    {
        $writable = fopen($this->directory.'/a', 'wb');
        self::assertIsResource($writable);
        self::assertTrue($this->files->write($writable, 'abc'));
        fclose($writable);

        self::assertSame('abc', file_get_contents($this->directory.'/a'));
        self::assertSame([], $this->logger->records);

        $readOnly = fopen($this->directory.'/a', 'rb');
        self::assertIsResource($readOnly);
        self::assertFalse($this->files->write($readOnly, 'abc'));
        fclose($readOnly);

        $this->assertLogged('a downloaded image could not be written.', ['bytes' => 3], 'fwrite(): ');
    }

    /**
     * The logger holds exactly one warning with this message, this context and a PHP error.
     *
     * @param string               $failure     End of the log message
     * @param array<string, mixed> $context     Log context without the error
     * @param non-empty-string     $errorPrefix Start of the PHP error
     *
     * @return void
     */
    private function assertLogged(string $failure, array $context, string $errorPrefix): void
    {
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        $error  = $record['context']['error'] ?? null;
        unset($record['context']['error']);

        self::assertSame(['level' => 'warning', 'message' => 'RGE Studio connector: '.$failure, 'context' => $context], $record);
        self::assertIsString($error);
        self::assertStringStartsWith($errorPrefix, $error);
    }
}
