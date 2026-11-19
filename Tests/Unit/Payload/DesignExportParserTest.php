<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Payload;

use MauticPlugin\RgeStudioConnectorBundle\Payload\DesignExportParser;
use MauticPlugin\RgeStudioConnectorBundle\Payload\InvalidExportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DesignExportParserTest extends TestCase
{
    /**
     * A body shaped like a real Custom Connector export is read into an export.
     *
     * @return void
     */
    public function testParsesTheConnectorBody(): void
    {
        $body = (string) json_encode([
            'name'             => 'Spring offer',
            'html'             => '<html><body><p>Hi</p></body></html>',
            'template_type'    => 'email',
            'structure'        => '{"page":{}}',
            'subject'          => 'Our spring offer',
            'title'            => 'Ignored title',
            'preheader'        => 'Twenty percent off',
            'meta_description' => 'Ignored description',
            'status'           => 'in_review',
            'edit_url'         => 'https://reallygoodemails.com/app/1/2/3/1234567/edit/message',
            'tags'             => ['mautic-campaign', ' spring '],
            'utm_parameters'   => ['utm_source' => 'newsletter'],
        ]);

        $export = (new DesignExportParser())->parse($body);

        self::assertSame('1234567', $export->designId);
        self::assertSame('Spring offer', $export->name);
        self::assertSame('Our spring offer', $export->subject);
        self::assertSame('Twenty percent off', $export->preheader);
        self::assertSame('in_review', $export->status);
        self::assertSame(['mautic-campaign', 'spring'], $export->tags);
        self::assertTrue($export->isEmail());
        self::assertSame(hash('sha256', $body), $export->hash);
    }

    /**
     * The page title and meta description stand in for a missing subject and preheader.
     *
     * @return void
     */
    public function testFallsBackToTitleAndMetaDescription(): void
    {
        $body = (string) json_encode([
            'html'             => '<p>Hi</p>',
            'title'            => 'Title as subject',
            'meta_description' => 'Description as preheader',
            'edit_url'         => 'https://example.com/app/1/2/3/42/edit/message',
        ]);

        $export = (new DesignExportParser())->parse($body);

        self::assertSame('42', $export->designId);
        self::assertSame('Title as subject', $export->subject);
        self::assertSame('Description as preheader', $export->preheader);
        self::assertSame('email', $export->templateType);
    }

    /**
     * Bodies that are not a usable export are rejected.
     *
     * @param string $body Request body
     *
     * @return void
     */
    #[DataProvider('unusableBodies')]
    public function testRejectsUnusableBodies(string $body): void
    {
        $this->expectException(InvalidExportException::class);

        (new DesignExportParser())->parse($body);
    }

    /**
     * Bodies the parser must reject.
     *
     * @return iterable<string, array{string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'not json' => ['<html></html>'];
        yield 'a list' => ['[1,2]'];
        yield 'no html' => ['{"edit_url":"https://example.com/app/1/edit"}'];
        yield 'no design address' => ['{"html":"<p>Hi</p>"}'];
    }
}
