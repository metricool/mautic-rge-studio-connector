<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Payload;

class DesignExportParser
{
    /**
     * Read the JSON body the RGE Studio Custom Connector posts.
     *
     * Only the keys the import needs are read. The design structure and the UTM parameters are ignored.
     *
     * @param string $body Raw request body
     *
     * @return DesignExport
     *
     * @throws InvalidExportException When the body is not a JSON object or has no HTML or design address
     */
    public function parse(string $body): DesignExport
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidExportException('The body is not valid JSON.');
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidExportException('The body is not a JSON object.');
        }

        $html = $this->text($data, 'html');
        if ('' === trim($html)) {
            throw new InvalidExportException('The export has no HTML.');
        }

        $designId = $this->designId($this->text($data, 'edit_url'));
        if (null === $designId) {
            throw new InvalidExportException('The export has no design address.');
        }

        $subject   = $this->text($data, 'subject');
        $preheader = $this->text($data, 'preheader');

        return new DesignExport(
            $designId,
            trim($this->text($data, 'name')),
            trim('' !== $subject ? $subject : $this->text($data, 'title')),
            trim('' !== $preheader ? $preheader : $this->text($data, 'meta_description')),
            $html,
            trim($this->text($data, 'status')),
            $this->tags($data['tags'] ?? []),
            '' !== $this->text($data, 'template_type') ? $this->text($data, 'template_type') : 'email',
            hash('sha256', $body),
        );
    }

    /**
     * Design id taken from the edit address, the last number in its path.
     *
     * @param string $editUrl Edit address of the design
     *
     * @return string|null
     */
    public function designId(string $editUrl): ?string
    {
        $path = parse_url(trim($editUrl), PHP_URL_PATH);
        if (!is_string($path) || 0 === preg_match_all('/\d+/', $path, $numbers)) {
            return null;
        }

        $trimmed = ltrim((string) end($numbers[0]), '0');

        return '' === $trimmed ? '0' : $trimmed;
    }

    /**
     * String value of a key, empty when missing or not a string.
     *
     * @param array<mixed> $data Decoded body
     * @param string       $key  Key to read
     *
     * @return string
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Tags as a list of trimmed, non-empty strings.
     *
     * @param mixed $tags Tags value from the body, a list or a comma-separated string
     *
     * @return list<string>
     */
    private function tags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        if (!is_array($tags)) {
            return [];
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (is_array($tag)) {
                $tag = $tag['name'] ?? $tag['label'] ?? '';
            }
            if (is_scalar($tag) && '' !== trim((string) $tag)) {
                $clean[] = trim((string) $tag);
            }
        }

        return $clean;
    }
}
