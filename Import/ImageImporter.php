<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ImageImporter
{
    /**
     * Build the importer.
     *
     * @param HttpClientInterface $httpClient Client that rejects private network addresses
     * @param PathsHelper         $paths      Mautic paths
     * @param ConnectorSettings   $settings   Plugin settings
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private PathsHelper $paths,
        private ConnectorSettings $settings,
    ) {
    }

    /**
     * Copy every remote image of the HTML into Mautic's media and point the HTML to the copies.
     *
     * Each address is replaced only where it was found, so a link or a personalised address that holds it stays as
     * designed. Images not copied by the deadline count as failed, and their address is emptied so the saved email
     * never points to the designer's storage.
     *
     * @param string $html     Email HTML
     * @param float  $deadline Unix time with microseconds when copying must stop
     *
     * @return ImageImportResult
     */
    public function import(string $html, float $deadline): ImageImportResult
    {
        [$references, $readable] = $this->references($html);
        $urls                    = array_values(array_unique(array_column($references, 'url')));
        $outcomes                = $this->copyAll(array_slice($urls, 0, $this->settings->maxImages()), $deadline);

        foreach (array_slice($urls, $this->settings->maxImages()) as $url) {
            $outcomes[$url] = ['error' => 'Too many images in one export.'];
        }

        $failures  = [];
        $rewritten = '';
        $offset    = 0;
        foreach ($references as $reference) {
            $outcome    = $outcomes[$reference['url']];
            $rewritten .= substr($html, $offset, $reference['start'] - $offset).($outcome['url'] ?? '');
            $offset     = $reference['start'] + $reference['length'];
            if (!isset($outcome['url'])) {
                $failures[$reference['url']] = ['url' => $reference['url'], 'reason' => $outcome['error'] ?? 'Not copied.'];
            }
        }
        $rewritten .= substr($html, $offset);

        if (!$readable) {
            $failures[] = ['url' => 'HTML', 'reason' => 'It could not be searched for images, so some may not be copied.'];
        }

        $copied = count(array_filter($outcomes, static fn (array $outcome): bool => isset($outcome['url'])));

        return new ImageImportResult($rewritten, $copied, array_merge(array_values($failures), $this->leftovers($rewritten)));
    }

    /**
     * Remote image addresses in the HTML, each with where it sits, in the order they appear.
     *
     * Covers src, background and poster attributes (quoted or not), srcset lists, CSS url() values
     * outside @import and @font-face, and any address on the designer's image storage, inside Outlook
     * conditional comments too. Addresses already on this Mautic, and addresses holding a Mautic token
     * such as a personalised image, are left out. Where two finds overlap, the first one is kept, and the
     * longer one when both start at the same place.
     *
     * @param string $html Email HTML
     *
     * @return array{list<array{start: int, length: int, url: string}>, bool} The addresses, and whether every pattern could run
     */
    private function references(string $html): array
    {
        $found    = [];
        $readable = true;
        foreach ([$this->attributeAddresses($html), $this->srcsetAddresses($html), $this->cssAddresses($html), $this->designerAddresses($html)] as $finds) {
            if (null === $finds) {
                $readable = false;
                continue;
            }
            array_push($found, ...$finds);
        }

        $candidates = [];
        foreach ($found as [$raw, $start]) {
            $trimmed = trim($raw);
            $url     = $this->absolute($trimmed);
            if ('' !== $trimmed && null !== $url) {
                $candidates[] = ['start' => $start + strlen($raw) - strlen(ltrim($raw)), 'length' => strlen($trimmed), 'url' => $url];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$a['start'], $b['length']] <=> [$b['start'], $a['length']]);

        $references = [];
        $end        = 0;
        foreach ($candidates as $candidate) {
            if ($candidate['start'] >= $end) {
                $references[] = $candidate;
                $end          = $candidate['start'] + $candidate['length'];
            }
        }

        return [$references, $readable];
    }

    /**
     * Texts a pattern captures, each with its byte offset in the HTML.
     *
     * For every match the first of the given groups that took part is kept.
     *
     * @param string    $pattern Regular expression
     * @param string    $subject Text to search
     * @param list<int> $groups  Groups to take, in order of preference
     * @param int       $base    Offset of the subject inside the HTML
     *
     * @return list<array{string, int}>|null Null when the pattern could not run
     */
    private function captures(string $pattern, string $subject, array $groups, int $base = 0): ?array
    {
        if (false === preg_match_all($pattern, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $found = [];
        foreach ($matches as $match) {
            foreach ($groups as $group) {
                if (isset($match[$group]) && -1 !== $match[$group][1]) {
                    $found[] = [$match[$group][0], $base + $match[$group][1]];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Addresses in src, background and poster attributes, quoted or not.
     *
     * @param string $html Email HTML
     *
     * @return list<array{string, int}>|null Null when the pattern could not run
     */
    private function attributeAddresses(string $html): ?array
    {
        return $this->captures('/\b(?:src|background|poster)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, [1, 2, 3]);
    }

    /**
     * Addresses in srcset lists, read the way browsers split them.
     *
     * @param string $html Email HTML
     *
     * @return list<array{string, int}>|null Null when a pattern could not run
     */
    private function srcsetAddresses(string $html): ?array
    {
        $lists = $this->captures('/\bsrcset\s*=\s*(["\'])(.*?)\1/is', $html, [2]);
        if (null === $lists) {
            return null;
        }

        $found = [];
        foreach ($lists as [$list, $offset]) {
            $candidates = $this->captures('/[\s,]*([^\s,](?:\S*[^\s,])?)(?:,+|\s+[^,]*)?/', $list, [1], $offset);
            if (null === $candidates) {
                return null;
            }
            array_push($found, ...$candidates);
        }

        return $found;
    }

    /**
     * Addresses in CSS url() values, outside @import rules and @font-face blocks.
     *
     * A quoted value may hold parentheses, an unquoted one may not.
     *
     * @param string $html Email HTML
     *
     * @return list<array{string, int}>|null Null when a pattern could not run
     */
    private function cssAddresses(string $html): ?array
    {
        $skipped = $this->captures('/@import[^;]*;|@font-face\s*\{[^}]*\}/i', $html, [0]);
        $quoted  = $this->captures('/url\(\s*(&quot;|&apos;|&#0*(?:34|39);|&#x0*(?:22|27);|["\'])(.+?)\1\s*\)/i', $html, [2]);
        $bare    = $this->captures('/url\(\s*([^\s"\'()&][^\s"\'()]*)\s*\)/i', $html, [1]);
        if (null === $skipped || null === $quoted || null === $bare) {
            return null;
        }

        return array_values(array_filter(
            array_merge($quoted, $bare),
            static function (array $find) use ($skipped): bool {
                foreach ($skipped as [$text, $start]) {
                    if ($find[1] >= $start && $find[1] < $start + strlen($text)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * Every address on the designer's image storage, wherever it appears.
     *
     * An address may hold balanced parentheses, as in hero(1).png, and ends before an unbalanced one, as in
     * an unquoted CSS url().
     *
     * @param string $html Email HTML
     *
     * @return list<array{string, int}>|null Null when a pattern could not run
     */
    private function designerAddresses(string $html): ?array
    {
        $found = [];
        foreach ($this->settings->designerHosts() as $host) {
            $finds = $this->captures('#(?:https?:)?//(?:[a-z0-9-]+\.)*'.preg_quote($host, '#').'(?:[/?\#](?:[^\s"\'<>()]|\([^\s"\'<>()]*\))*)?#i', $html, [0]);
            if (null === $finds) {
                return null;
            }
            array_push($found, ...$finds);
        }

        return $found;
    }

    /**
     * Download every address, trying again the ones that may succeed later, within the deadline.
     *
     * @param list<string> $urls     Addresses to download
     * @param float        $deadline Unix time with microseconds when copying must stop
     *
     * @return array<string, array{url?: string, error?: string, retry?: bool}>
     */
    private function copyAll(array $urls, float $deadline): array
    {
        $outcomes = [];
        $pending  = $urls;
        for ($attempt = 1; [] !== $pending && $attempt <= $this->settings->imageAttempts(); ++$attempt) {
            $retry = [];
            foreach ($this->download($pending, $deadline) as $url => $outcome) {
                $outcomes[$url] = $outcome;
                if ($outcome['retry'] ?? false) {
                    $retry[] = $url;
                }
            }
            $pending = microtime(true) < $deadline ? $retry : [];
        }

        return $outcomes;
    }

    /**
     * Designer storage addresses still in the HTML after the copies, as failures.
     *
     * @param string $html Email HTML with the copies in place
     *
     * @return list<array{url: string, reason: string}>
     */
    private function leftovers(string $html): array
    {
        $failures = [];
        foreach ($this->settings->designerHosts() as $host) {
            if (false !== stripos($html, $host)) {
                $failures[] = ['url' => $host, 'reason' => 'An address on the designer\'s storage is still in the HTML.'];
            }
        }

        return $failures;
    }

    /**
     * Download address for a text found in the HTML, or null when it is not a remote image.
     *
     * @param string $raw Text as found in the HTML
     *
     * @return string|null
     */
    private function absolute(string $raw): ?string
    {
        $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5));
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        if (1 !== preg_match('#^https?://[^/\s{}]+#i', $url) || 1 === preg_match('/\{|%7B/i', $url)) {
            return null;
        }

        foreach ($this->settings->mauticBaseUrls() as $base) {
            if (0 === stripos($url, $base.'/')) {
                return null;
            }
        }

        return $url;
    }

    /**
     * Download images at the same time, each into the image folder.
     *
     * @param list<string> $urls     Addresses to download
     * @param float        $deadline Unix time with microseconds when downloads must stop
     *
     * @return array<string, array{url?: string, error?: string, retry?: bool}>
     */
    private function download(array $urls, float $deadline): array
    {
        $directory = $this->directory();
        $batch     = new ImageDownloadBatch($directory, $this->settings->maxImageBytes());
        foreach ($urls as $url) {
            $this->start($batch, $url, $deadline);
        }

        while ($batch->isRunning() && microtime(true) < $deadline) {
            $idle = max(0.1, min(1.0, $deadline - microtime(true)));
            foreach ($this->httpClient->stream($batch->responses(), $idle) as $response => $chunk) {
                if ($batch->receive($response, $chunk, $deadline)) {
                    break;
                }
            }
        }
        $batch->cancelRemaining();

        $outcomes = [];
        foreach ($batch->outcomes() as $url => $outcome) {
            $outcomes[$url] = isset($outcome['path']) ? $this->store($outcome['path'], $directory) : $outcome;
        }

        return $outcomes;
    }

    /**
     * Start one download, or record why it cannot start.
     *
     * @param ImageDownloadBatch $batch    Batch the download belongs to
     * @param string             $url      Address of the image
     * @param float              $deadline Unix time with microseconds when downloads must stop
     *
     * @return void
     */
    private function start(ImageDownloadBatch $batch, string $url, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining < 0.5) {
            $batch->fail($url, 'Not copied in time.', false);

            return;
        }

        try {
            $batch->add($url, $this->httpClient->request('GET', $url, [
                'timeout'       => min(10.0, $remaining),
                'max_duration'  => $remaining,
                'max_redirects' => 3,
                'headers'       => ['Accept' => 'image/*'],
                'user_data'     => $url,
            ]));
        } catch (ExceptionInterface) {
            $batch->fail($url, 'The download could not start.', true);
        }
    }

    /**
     * Keep a downloaded file when it is an image, named after its content.
     *
     * @param string $temporary Temporary file in the image folder
     * @param string $directory Image folder
     *
     * @return array{url?: string, error?: string}
     */
    private function store(string $temporary, string $directory): array
    {
        $extension = $this->extension($temporary);
        if (null === $extension) {
            @unlink($temporary);

            return ['error' => 'The file is not a PNG, JPEG, GIF or WebP image.'];
        }

        $hash = hash_file('sha256', $temporary);
        if (false === $hash) {
            @unlink($temporary);

            return ['error' => 'The downloaded file could not be read.'];
        }

        $name   = $hash.'.'.$extension;
        $target = $directory.'/'.$name;
        if (is_file($target)) {
            @unlink($temporary);
        } elseif (!rename($temporary, $target)) {
            @unlink($temporary);

            return ['error' => 'The image could not be saved in the media folder.'];
        } else {
            @chmod($target, 0644);
        }

        return ['url' => $this->publicUrl($name)];
    }

    /**
     * File extension from the file's first bytes, or null when it is not an allowed image.
     *
     * @param string $path File to inspect
     *
     * @return string|null
     */
    private function extension(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);

        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'gif';
        }
        if (str_starts_with($head, 'RIFF') && 'WEBP' === substr($head, 8, 4)) {
            return 'webp';
        }

        return null;
    }

    /**
     * Absolute folder that holds the copied images, created when missing.
     *
     * @return string
     */
    private function directory(): string
    {
        $directory = rtrim((string) $this->paths->getSystemPath('images', true), '/').'/'.$this->settings->imageFolder();
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    /**
     * Public address of a copied image, built the way Mautic's builder builds it.
     *
     * @param string $name File name inside the image folder
     *
     * @return string
     */
    private function publicUrl(string $name): string
    {
        $base     = $this->settings->mauticBaseUrls()[0] ?? '';
        $relative = trim((string) $this->paths->getSystemPath('images', false), '/');

        return $base.'/'.$relative.'/'.$this->settings->imageFolder().'/'.$name;
    }
}
