<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Mautic\EmailBundle\Helper\MailHelper;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;

class UnsubscribeLinkChecker
{
    private const URL_TOKEN  = '{unsubscribe_url}';
    private const TEXT_TOKEN = '{unsubscribe_text}';

    /**
     * Build the checker.
     *
     * @param ConnectorSettings $settings Plugin settings
     */
    public function __construct(private ConnectorSettings $settings)
    {
    }

    /**
     * Turn a link address made of a scheme and a lone Mautic token into the token alone.
     *
     * Mautic does the same at send time, but its link check rejects the address with the scheme.
     *
     * @param string $html Email HTML
     *
     * @return string
     */
    public function normalise(string $html): string
    {
        return (string) preg_replace('/(\shref\s*=\s*["\']?)\s*(?:https?:)?\/\/(\{[a-z_]+\})(?=["\'\s>])/i', '$1$2', $html);
    }

    /**
     * Problems with the unsubscribe link, in plain words for the email's description.
     *
     * @param string $html Email HTML as it will be stored
     *
     * @return list<string>
     */
    public function check(string $html): array
    {
        $problems = [];
        $visible  = (string) preg_replace('/<!--.*?-->/s', '', $html);
        $outside  = $this->scan($visible);
        $anywhere = $this->scan($html);

        if (!$outside['working']) {
            $problems[] = $anywhere['working']
                ? 'The unsubscribe link is only inside HTML comments, so most email apps never show it.'
                : 'There is no working unsubscribe link. Use {unsubscribe_url} as the whole link address, or {unsubscribe_text} as text.';
        }

        if ($anywhere['broken']) {
            $problems[] = 'An unsubscribe token sits inside a longer address or inside an attribute, so that link breaks.';
        }

        if ($outside['working'] && !MailHelper::isUnsubscribeHeadersRequired($html, $this->settings->unsubscribeTextSetting())) {
            $problems[] = 'Mautic will send no List-Unsubscribe header. Use {unsubscribe_url} in lower case, or an unsubscribe text setting that holds |URL|.';
        }

        preg_match_all('/\{unsubscribe_(?:url|text)\}/i', $html, $tokens);
        foreach ($tokens[0] as $token) {
            if ($token !== strtolower($token)) {
                $problems[] = 'An unsubscribe token is not all in lower case, so it gets no List-Unsubscribe header and no tracking.';
                break;
            }
        }

        if (1 === preg_match('/%7B\s*(?:unsubscribe_(?:url|text)|dnc_url)/i', $html)) {
            $problems[] = 'An unsubscribe token is URL-encoded (%7B...%7D), and Mautic never replaces it.';
        }

        if (1 === preg_match('/(?:&#0*123;|&#x0*7b;|&lbrace;|&lcub;)\s*unsubscribe_/i', $html)) {
            $problems[] = 'An unsubscribe token is written with HTML entities, and Mautic never replaces it.';
        }

        foreach ($this->settings->pastedUnsubscribePaths() as $path) {
            if (false !== stripos($html, $path)) {
                $problems[] = 'A pasted unsubscribe address ('.$path.') is in the HTML. It only works for one contact.';
            }
        }

        return $problems;
    }

    /**
     * Find working and broken unsubscribe tokens.
     *
     * A token works as the whole href of a link ({unsubscribe_url}) or as text ({unsubscribe_text}).
     * It breaks inside a longer address, or ({unsubscribe_text}) inside any attribute.
     *
     * @param string $html HTML to scan
     *
     * @return array{working: bool, broken: bool}
     */
    private function scan(string $html): array
    {
        $working = false;
        $broken  = false;

        preg_match_all('/<[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', $html, $tags);
        foreach ($tags[0] as $tag) {
            preg_match_all('/([^\s=\/>]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/s', $tag, $attributes, PREG_SET_ORDER);
            foreach ($attributes as $attribute) {
                $name  = strtolower($attribute[1]);
                $value = ($attribute[2] ?? '').($attribute[3] ?? '').($attribute[4] ?? '');

                if (str_contains($value, self::URL_TOKEN)) {
                    if ('href' === $name && self::URL_TOKEN === trim($value)) {
                        $working = true;
                    } else {
                        $broken = true;
                    }
                }

                if (str_contains($value, self::TEXT_TOKEN)) {
                    $broken = true;
                }
            }
        }

        $text = (string) preg_replace('/<[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>|<[\/!][^>]*>/s', ' ', $html);
        if (str_contains($text, self::TEXT_TOKEN)) {
            $working = true;
        }

        return ['working' => $working, 'broken' => $broken];
    }
}
