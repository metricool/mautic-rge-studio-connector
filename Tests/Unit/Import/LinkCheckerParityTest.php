<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Validator\ValidEmailLinks;
use MauticPlugin\RgeStudioConnectorBundle\Import\LinkChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class LinkCheckerParityTest extends TestCase
{
    /**
     * Where Mautic has its own link rule (7.2 and later), the plugin's check reports exactly the links it rejects.
     *
     * @return void
     */
    public function testMatchesMauticsLinkRule(): void
    {
        if (!class_exists(ValidEmailLinks::class)) {
            self::markTestSkipped('This Mautic has no link rule of its own.');
        }

        $hrefs = [
            '{unsubscribe_url}', '{webview_url}', '#top', '#', 'https://example.com', 'https://example.com/spring offer/?a=1&amp;b=2',
            'http://example.com/a', 'HTTPS://example.com', 'ftp://example.com/file.pdf', 'ftps://example.com/file.pdf',
            'mailto:hello@example.com', 'tel:+34600000000', 'sms:+34600000000', 'www.example.com', 'javascript:alert(1)', '',
            'https://exa mple.com', '{unsubscribe_url}x', 'https://', '/spring', 'javascript:alert(&quot;x&quot;)', 'javascript:alert(&amp;apos;x&amp;apos;)', 'data:text/html,x',
            'https://example.com/%zz', 'https://user@example.com', '  https://example.com', 'https://example.com#a b',
        ];
        $html = '<body>'.implode('', array_map(static fn (string $href): string => '<a href="'.$href.'">x</a>', $hrefs)).'</body>';

        $email = new Email();
        $email->setCustomHtml($html);
        $rejected = [];
        foreach (Validation::createValidator()->validate($email, new ValidEmailLinks()) as $violation) {
            $url        = $violation->getParameters()['%url%'] ?? '';
            $rejected[] = is_string($url) ? $url : '';
        }

        self::assertNotSame([], $rejected);
        self::assertSame(array_values(array_unique($rejected)), (new LinkChecker())->invalidLinks($html));
    }
}
