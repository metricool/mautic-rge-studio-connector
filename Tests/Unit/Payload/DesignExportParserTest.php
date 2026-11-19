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
     * Bodies that are not a usable export are rejected with the reason.
     *
     * @param string $body    Request body
     * @param string $message Reason expected
     *
     * @return void
     */
    #[DataProvider('unusableBodies')]
    public function testRejectsUnusableBodies(string $body, string $message): void
    {
        $this->expectException(InvalidExportException::class);
        $this->expectExceptionMessage($message);

        (new DesignExportParser())->parse($body);
    }

    /**
     * Bodies the parser must reject, with the reason given.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'not json' => ['<html></html>', 'The body is not valid JSON.'];
        yield 'a list' => ['[1,2]', 'The body is not a JSON object.'];
        yield 'a list with html' => ['[{"html":"<p>Hi</p>","edit_url":"https://example.com/app/1/edit"}]', 'The body is not a JSON object.'];
        yield 'no html' => ['{"edit_url":"https://example.com/app/1/edit"}', 'The export has no HTML.'];
        yield 'blank html' => ['{"html":"  \n ","edit_url":"https://example.com/app/1/edit"}', 'The export has no HTML.'];
        yield 'no design address' => ['{"html":"<p>Hi</p>"}', 'The export has no design address.'];
        yield 'nested too deep' => [self::nested(63), 'The body is not valid JSON.'];
    }

    /**
     * A body nested as deep as the limit allows is still read.
     *
     * @return void
     */
    public function testReadsABodyAtTheNestingLimit(): void
    {
        self::assertSame('1', (new DesignExportParser())->parse(self::nested(62))->designId);
    }

    /**
     * Fields are trimmed, numbers are read as text, and tags without text are dropped.
     *
     * @return void
     */
    public function testCleansTheFields(): void
    {
        $body = (string) json_encode([
            'html'          => '<p>Hi</p>',
            'edit_url'      => 'https://example.com/app/1/2/3/42/edit/message',
            'name'          => ' Spring offer ',
            'subject'       => '  Our offer  ',
            'preheader'     => '  Twenty percent off  ',
            'status'        => 5,
            'template_type' => 'EMAIL',
            'tags'          => ['spring', '', '  ', ['name' => 'named', 'label' => 'ignored'], ['label' => 'labelled'], ['other' => 'x'], null],
        ]);

        $export = (new DesignExportParser())->parse($body);

        self::assertSame('Spring offer', $export->name);
        self::assertSame('Our offer', $export->subject);
        self::assertSame('Twenty percent off', $export->preheader);
        self::assertSame('5', $export->status);
        self::assertSame(['spring', 'named', 'labelled'], $export->tags);
        self::assertTrue($export->isEmail());
    }

    /**
     * Tags may also arrive as comma separated text.
     *
     * @return void
     */
    public function testReadsTagsFromText(): void
    {
        $body   = (string) json_encode(['html' => '<p>Hi</p>', 'edit_url' => 'https://example.com/app/7', 'tags' => 'a, b,,', 'status' => ' draft ']);
        $export = (new DesignExportParser())->parse($body);

        self::assertSame(['a', 'b'], $export->tags);
        self::assertSame('draft', $export->status);
    }

    /**
     * A design address of only zeros gives the id 0.
     *
     * @return void
     */
    public function testDesignIdOfZeros(): void
    {
        self::assertSame('0', (new DesignExportParser())->designId('https://example.com/app/000'));
        self::assertNull((new DesignExportParser())->designId('not an address with digits'));
        self::assertNull((new DesignExportParser())->designId(' https://host9.example.com/app/'));
    }

    /**
     * Export body whose structure field holds arrays nested inside the top object.
     *
     * @param int $levels Arrays nested inside the top object
     *
     * @return string
     */
    private static function nested(int $levels): string
    {
        return '{"html":"<p>Hi</p>","edit_url":"https://example.com/app/1","structure":'
            .str_repeat('[', $levels).'1'.str_repeat(']', $levels).'}';
    }
}
