<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
        $urls       = array_values(array_unique(array_values($references)));
        $outcomes   = [];

        foreach (array_slice($urls, $this->settings->maxImages()) as $url) {
            $outcomes[$url] = ['error' => 'Too many images in one export.'];
        }

        $pending = array_slice($urls, 0, $this->settings->maxImages());
        for ($attempt = 1; [] !== $pending && $attempt <= $this->settings->imageAttempts(); ++$attempt) {
            $retry = [];
            foreach ($this->download($pending, $deadline) as $url => $outcome) {
                $outcomes[$url] = $outcome;
                if (isset($outcome['retry']) && true === $outcome['retry']) {
                    $retry[] = $url;
                }
            }
            $pending = microtime(true) < $deadline ? $retry : [];
        }

        uksort($references, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $copied   = [];
        $failures = [];
        $search   = [];
        $replace  = [];
        foreach ($references as $raw => $url) {
            $outcome  = $outcomes[$url] ?? ['error' => 'Not copied in time.'];
            $search[] = $raw;
            if (isset($outcome['url'])) {
                $replace[]    = $outcome['url'];
                $copied[$url] = true;
            } else {
                $replace[]      = '';
                $failures[$url] = ['url' => $url, 'reason' => $outcome['error'] ?? 'Not copied.'];
            }
        }

        $html = str_replace($search, $replace, $html);

        foreach ($this->settings->designerHosts() as $host) {
            if (false !== stripos($html, $host)) {
                $failures['host:'.$host] = ['url' => $host, 'reason' => 'An address on the designer\'s storage is still in the HTML.'];
            }
        }

        return new ImageImportResult($html, count($copied), array_values($failures));
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
    public function references(string $html): array
    {
        $found = [];

        preg_match_all('/\b(?:src|background|poster)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $found[] = ($match[1] ?? '').($match[2] ?? '').($match[3] ?? '');
        }

        preg_match_all('/\bsrcset\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match_all('/[\s,]*([^\s,](?:\S*[^\s,])?)(?:,+|\s+[^,]*)?/', ($match[1] ?? '').($match[2] ?? ''), $candidates);
            foreach ($candidates[1] as $candidate) {
                $found[] = $candidate;
            }
        }

        $css = (string) preg_replace(['/@import[^;]*;/i', '/@font-face\s*\{[^}]*\}/i'], '', $html);
        preg_match_all('/url\(\s*((?:&quot;|&apos;|&#0*(?:34|39);|&#x0*(?:22|27);|["\'])?)([^"\')]+?)\1\s*\)/i', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $found[] = $match[2];
        }

        foreach ($this->settings->designerHosts() as $host) {
            preg_match_all('#(?:https?:)?//(?:[a-z0-9-]+\.)*'.preg_quote($host, '#').'(?:[/?\#][^\s"\'<>()]*)?#i', $html, $matches);
            foreach ($matches[0] as $address) {
                $found[] = $address;
            }
        }

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
        $outcomes  = [];
        $active    = [];
        $files     = [];
        $sizes     = [];

        foreach ($urls as $url) {
            $remaining = $deadline - microtime(true);
            if ($remaining < 0.5) {
                $outcomes[$url] = ['error' => 'Not copied in time.'];
                continue;
            }

            try {
                $active[$url] = $this->httpClient->request('GET', $url, [
                    'timeout'       => min(10.0, $remaining),
                    'max_duration'  => $remaining,
                    'max_redirects' => 3,
                    'headers'       => ['Accept' => 'image/*'],
                    'user_data'     => $url,
                ]);
            } catch (ExceptionInterface) {
                $outcomes[$url] = ['error' => 'The download could not start.', 'retry' => true];
            }
        }

        while ([] !== $active && microtime(true) < $deadline) {
            $idle = max(0.1, min(1.0, $deadline - microtime(true)));
            foreach ($this->httpClient->stream(array_values($active), $idle) as $response => $chunk) {
                $url = $this->urlOf($response);
                if (!isset($active[$url])) {
                    continue;
                }

                try {
                    if ($chunk->isTimeout()) {
                        if (microtime(true) >= $deadline) {
                            break;
                        }
                        continue;
                    }

                    if ($chunk->isFirst()) {
                        $status = $response->getStatusCode();
                        if (200 !== $status) {
                            $outcomes[$url] = ['error' => 'The image server answered '.$status.'.', 'retry' => $status >= 500 || 429 === $status];
                            $this->stop($response, $active, $files, $url);
                            continue;
                        }

                        $length = $response->getHeaders(false)['content-length'][0] ?? null;
                        if (null !== $length && (int) $length > $this->settings->maxImageBytes()) {
                            $outcomes[$url] = ['error' => 'The image is larger than the limit.'];
                            $this->stop($response, $active, $files, $url);
                            continue;
                        }

                        $path = tempnam($directory, '.rge');
                        $file = false === $path ? false : fopen($path, 'wb');
                        if (false === $path || false === $file) {
                            $outcomes[$url] = ['error' => 'The image folder is not writable.'];
                            $this->stop($response, $active, $files, $url);
                            continue;
                        }
                        $files[$url] = ['path' => $path, 'handle' => $file];
                        $sizes[$url] = 0;
                    }

                    $content = $chunk->getContent();
                    if ('' !== $content && isset($files[$url])) {
                        $sizes[$url] += strlen($content);
                        if ($sizes[$url] > $this->settings->maxImageBytes()) {
                            $outcomes[$url] = ['error' => 'The image is larger than the limit.'];
                            $this->stop($response, $active, $files, $url);
                            continue;
                        }
                        fwrite($files[$url]['handle'], $content);
                    }

                    if ($chunk->isLast() && isset($files[$url])) {
                        fclose($files[$url]['handle']);
                        $outcomes[$url] = $this->store($files[$url]['path'], $directory);
                        unset($files[$url], $active[$url]);
                    }
                } catch (ExceptionInterface) {
                    $outcomes[$url] = ['error' => 'The download failed.', 'retry' => true];
                    $this->stop($response, $active, $files, $url);
                }
            }
        }

        foreach (array_keys($active) as $url) {
            $outcomes[$url] = ['error' => 'Not copied in time.'];
            $this->stop($active[$url], $active, $files, $url);
        }

        return $outcomes;
    }

    /**
     * Cancel a download and drop its temporary file.
     *
     * @param ResponseInterface                                    $response Download to cancel
     * @param array<string, ResponseInterface>                     $active   Downloads still running
     * @param array<string, array{path: string, handle: resource}> $files    Temporary files
     * @param string                                               $url      Address of the download
     *
     * @return void
     */
    private function stop(ResponseInterface $response, array &$active, array &$files, string $url): void
    {
        $response->cancel();
        unset($active[$url]);

        if (isset($files[$url])) {
            if (is_resource($files[$url]['handle'])) {
                fclose($files[$url]['handle']);
            }
            @unlink($files[$url]['path']);
            unset($files[$url]);
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

    /**
     * Address a download was started for.
     *
     * @param ResponseInterface $response Download
     *
     * @return string
     */
    private function urlOf(ResponseInterface $response): string
    {
        $url = $response->getInfo('user_data');

        return is_string($url) ? $url : '';
    }
}
