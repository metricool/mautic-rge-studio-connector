<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Symfony\Component\DomCrawler\Crawler;

class LinkChecker
{
    private const CHECKED_SCHEMES  = ['http', 'https', 'ftp', 'ftps'];
    private const ACCEPTED_SCHEMES = ['mailto', 'tel', 'sms'];

    /**
     * Link addresses that are not valid, each listed once.
     *
     * The rule is the one Mautic 7.2 applies when an email is saved in its editor: a link is a whole
     * Mautic token, an anchor, a valid http, https, ftp or ftps address, or a mailto, tel or sms link.
     *
     * @param string $html Email HTML
     *
     * @return list<string>
     */
    public function invalidLinks(string $html): array
    {
        $invalid = [];
        foreach ((new Crawler($html))->filter('a[href]') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            $url = html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5);
            if (!$this->isValid($url)) {
                $invalid[$url] = $url;
            }
        }

        return array_values($invalid);
    }

    /**
     * Whether one link address passes the rule.
     *
     * @param string $url Link address, entities decoded
     *
     * @return bool
     */
    private function isValid(string $url): bool
    {
        if (1 === preg_match('/^\{[^{}]+\}$/', $url) || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, self::CHECKED_SCHEMES, true)) {
            return $this->isValidAddress($url);
        }

        return in_array($scheme, self::ACCEPTED_SCHEMES, true);
    }

    /**
     * Whether a web or file transfer address is valid once each part of its path is encoded.
     *
     * @param string $url Link address
     *
     * @return bool
     */
    private function isValidAddress(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path)) {
            $url = str_replace($path, implode('/', array_map(urlencode(...), explode('/', $path))), $url);
        }

        return false !== filter_var($url, FILTER_VALIDATE_URL);
    }
}
