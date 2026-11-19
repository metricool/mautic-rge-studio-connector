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
     * Images not copied by the deadline count as failed, and their address is emptied so the
     * saved email never points to the designer's storage.
     *
     * @param string $html     Email HTML
     * @param float  $deadline Unix time with microseconds when copying must stop
     *
     * @return ImageImportResult
     */
    public function import(string $html, float $deadline): ImageImportResult
    {
        $references = $this->references($html);
        $urls       = array_values(array_unique($references));
        $outcomes   = $this->copyAll(array_slice($urls, 0, $this->settings->maxImages()), $deadline);

        foreach (array_slice($urls, $this->settings->maxImages()) as $url) {
            $outcomes[$url] = ['error' => 'Too many images in one export.'];
        }

        uksort($references, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $failures = [];
        $search   = [];
        $replace  = [];
        foreach ($references as $raw => $url) {
            $outcome   = $outcomes[$url];
            $search[]  = $raw;
            $replace[] = $outcome['url'] ?? '';
            if (!isset($outcome['url'])) {
                $failures[$url] = ['url' => $url, 'reason' => $outcome['error'] ?? 'Not copied.'];
            }
        }

        $html   = str_replace($search, $replace, $html);
        $copied = count(array_filter($outcomes, static fn (array $outcome): bool => isset($outcome['url'])));

        return new ImageImportResult($html, $copied, array_merge(array_values($failures), $this->leftovers($html)));
    }

    /**
     * Remote image addresses in the HTML, keyed by the exact text found.
     *
     * Covers src, background and poster attributes (quoted or not), srcset lists, CSS url() values
     * outside @import and @font-face, and any address on the designer's image storage, inside Outlook
     * conditional comments too. Addresses already on this Mautic, and addresses holding a Mautic token
     * such as a personalised image, are left out.
     *
     * @param string $html Email HTML
     *
     * @return array<string, string> Text as found in the HTML, mapped to the address to download
     */
    private function references(string $html): array
    {
        $found = array_merge(
            $this->attributeAddresses($html),
            $this->srcsetAddresses($html),
            $this->cssAddresses($html),
            $this->designerAddresses($html),
        );

        $references = [];
        foreach ($found as $raw) {
            $raw = trim($raw);
            $url = $this->absolute($raw);
            if ('' !== $raw && null !== $url) {
                $references[$raw] = $url;
            }
        }

        return $references;
    }

    /**
     * Addresses in src, background and poster attributes, quoted or not.
     *
     * @param string $html Email HTML
     *
     * @return list<string>
     */
    private function attributeAddresses(string $html): array
    {
        preg_match_all('/\b(?:src|background|poster)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): string => ($match[1] ?? '').($match[2] ?? '').($match[3] ?? ''), $matches);
    }

    /**
     * Addresses in srcset lists, read the way browsers split them.
     *
     * @param string $html Email HTML
     *
     * @return list<string>
     */
    private function srcsetAddresses(string $html): array
    {
        preg_match_all('/\bsrcset\s*=\s*(["\'])(.*?)\1/is', $html, $matches);

        $found = [];
        foreach ($matches[2] as $list) {
            preg_match_all('/[\s,]*([^\s,](?:\S*[^\s,])?)(?:,+|\s+[^,]*)?/', $list, $candidates);
            array_push($found, ...$candidates[1]);
        }

        return $found;
    }

    /**
     * Addresses in CSS url() values, outside @import rules and @font-face blocks.
     *
     * @param string $html Email HTML
     *
     * @return list<string>
     */
    private function cssAddresses(string $html): array
    {
        $css = preg_replace(['/@import[^;]*;/i', '/@font-face\s*\{[^}]*\}/i'], '', $html) ?? '';
        preg_match_all('/url\(\s*((?:&quot;|&apos;|&#0*(?:34|39);|&#x0*(?:22|27);|["\'])?)([^"\')]+?)\1\s*\)/i', $css, $matches);

        return $matches[2];
    }

    /**
     * Every address on the designer's image storage, wherever it appears.
     *
     * @param string $html Email HTML
     *
     * @return list<string>
     */
    private function designerAddresses(string $html): array
    {
        $found = [];
        foreach ($this->settings->designerHosts() as $host) {
            preg_match_all('#(?:https?:)?//(?:[a-z0-9-]+\.)*'.preg_quote($host, '#').'(?:[/?\#][^\s"\'<>()]*)?#i', $html, $matches);
            array_push($found, ...$matches[0]);
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
