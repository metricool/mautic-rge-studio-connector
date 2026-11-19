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
     * @param ConnectorSettings $settings   Plugin settings
     * @param bool              $headerRule Whether this Mautic adds List-Unsubscribe only when the HTML asks for it (7.2 and later)
     */
    public function __construct(private ConnectorSettings $settings, private bool $headerRule)
    {
    }

    /**
     * Whether this Mautic adds List-Unsubscribe only when the HTML asks for it (7.2 and later).
     *
     * @return bool
     */
    public static function mauticHasHeaderRule(): bool
    {
        return (new \ReflectionClass(MailHelper::class))->hasMethod('isUnsubscribeHeadersRequired');
    }

    /**
     * Turn a link address made of a scheme and a lone Mautic token into the token alone.
     *
     * Mautic does the same at send time, but its link check rejects the address with the scheme. When the
     * pattern cannot run on the HTML, the HTML is kept as it is.
     *
     * @param string $html Email HTML
     *
     * @return string
     */
    public function normalise(string $html): string
    {
        return preg_replace('/(\shref\s*=\s*["\']?)\s*(?:https?:)?\/\/(\{[a-z_]+\})(?=["\'\s>])/i', '$1$2', $html) ?? $html;
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
        return array_merge($this->linkProblems($html), $this->headerProblems($html), $this->tokenProblems($html), $this->pastedProblems($html));
    }

    /**
     * Problems with where the unsubscribe link sits.
     *
     * @param string $html Email HTML as it will be stored
     *
     * @return list<string>
     */
    private function linkProblems(string $html): array
    {
        $problems = [];
        $outside  = $this->scan($this->withoutComments($html));
        $anywhere = $this->scan($html);

        if (!$outside['working']) {
            $problems[] = $anywhere['working']
                ? 'The unsubscribe link is only inside HTML comments, so most email apps never show it.'
                : 'There is no working unsubscribe link. Use {unsubscribe_url} as the whole link address, or {unsubscribe_text} as text.';
        }

        if ($anywhere['broken']) {
            $problems[] = 'An unsubscribe token sits inside a longer address or inside an attribute, so that link breaks.';
        }

        return $problems;
    }

    /**
     * Problems with the List-Unsubscribe header, where Mautic adds it only when the HTML asks for it.
     *
     * Older Mautic versions add the header to every email that is not transactional, so nothing is checked there.
     *
     * @param string $html Email HTML as it will be stored
     *
     * @return list<string>
     */
    private function headerProblems(string $html): array
    {
        if (!$this->headerRule) {
            return [];
        }

        $problems = [];
        if ($this->scan($this->withoutComments($html))['working'] && !MailHelper::isUnsubscribeHeadersRequired($html, $this->settings->unsubscribeTextSetting())) {
            $problems[] = 'Mautic will send no List-Unsubscribe header. Use {unsubscribe_url} in lower case, or an unsubscribe text setting that holds |URL|.';
        }

        preg_match_all('/\{unsubscribe_(?:url|text)\}/i', $html, $tokens);
        foreach ($tokens[0] as $token) {
            if ($token !== strtolower($token)) {
                $problems[] = 'An unsubscribe token is not all in lower case, so it gets no List-Unsubscribe header and no tracking.';
                break;
            }
        }

        return $problems;
    }

    /**
     * HTML without its comments.
     *
     * @param string $html Email HTML
     *
     * @return string
     */
    private function withoutComments(string $html): string
    {
        return preg_replace('/<!--.*?-->/s', '', $html) ?? '';
    }

    /**
     * Problems with how the unsubscribe tokens are written.
     *
     * @param string $html Email HTML as it will be stored
     *
     * @return list<string>
     */
    private function tokenProblems(string $html): array
    {
        $problems = [];

        if (1 === preg_match('/%7B\s*(?:unsubscribe_(?:url|text)|dnc_url)/i', $html)) {
            $problems[] = 'An unsubscribe token is URL-encoded (%7B...%7D), and Mautic never replaces it.';
        }

        if (1 === preg_match('/(?:&#0*123;|&#x0*7b;|&lbrace;|&lcub;)\s*unsubscribe_/i', $html)) {
            $problems[] = 'An unsubscribe token is written with HTML entities, and Mautic never replaces it.';
        }

        return $problems;
    }

    /**
     * Unsubscribe addresses pasted from a real email, which only work for one contact.
     *
     * @param string $html Email HTML as it will be stored
     *
     * @return list<string>
     */
    private function pastedProblems(string $html): array
    {
        $problems = [];
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
        $found = ['working' => false, 'broken' => false];

        preg_match_all('/<[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', $html, $tags);
        foreach ($tags[0] as $tag) {
            $found = $this->scanTag($tag, $found);
        }

        $text = preg_replace('/<[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>|<[\/!][^>]*>/s', ' ', $html) ?? '';
        if (str_contains($text, self::TEXT_TOKEN)) {
            $found['working'] = true;
        }

        return $found;
    }

    /**
     * Add the working and broken tokens found in one tag's attributes.
     *
     * @param string                             $tag   Opening tag
     * @param array{working: bool, broken: bool} $found What was found so far
     *
     * @return array{working: bool, broken: bool}
     */
    private function scanTag(string $tag, array $found): array
    {
        preg_match_all('/([^\s=\/>]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/s', $tag, $attributes, PREG_SET_ORDER);
        foreach ($attributes as $attribute) {
            $value = $this->attributeValue($attribute);

            if (str_contains($value, self::URL_TOKEN)) {
                if ('href' === strtolower($attribute[1]) && self::URL_TOKEN === trim($value)) {
                    $found['working'] = true;
                } else {
                    $found['broken'] = true;
                }
            }

            if (str_contains($value, self::TEXT_TOKEN)) {
                $found['broken'] = true;
            }
        }

        return $found;
    }

    /**
     * Value of a matched attribute, whatever quotes it used.
     *
     * @param array<int, string> $attribute Attribute match: name, then the double-quoted, single-quoted or bare value
     *
     * @return string
     */
    private function attributeValue(array $attribute): string
    {
        return ($attribute[2] ?? '').($attribute[3] ?? '').($attribute[4] ?? '');
    }
}
