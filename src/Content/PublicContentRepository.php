<?php

declare(strict_types=1);

namespace TriCab\Api\Content;

use RuntimeException;

/**
 * Loads version-controlled public marketing content (About / Contact) from JSON files.
 * No database involvement — shared source of truth for API clients.
 */
final class PublicContentRepository
{
    public function __construct(
        private readonly string $contentRoot,
        private readonly string $publicMediaRoot,
    ) {
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        return new self(
            rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/resources/content/public',
            rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/public/media/about',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function about(): array
    {
        $payload = $this->decodeJsonFile($this->contentRoot . '/about.json');
        $media = is_array($payload['media'] ?? null) ? $payload['media'] : [];
        $resolvedMedia = [];
        foreach ($media as $key => $item) {
            if (!is_string($key) || !is_array($item)) {
                continue;
            }
            $path = isset($item['path']) && is_string($item['path']) ? $item['path'] : null;
            $alt = isset($item['alt']) && is_string($item['alt']) ? $item['alt'] : '';
            if ($path === null || $path === '') {
                continue;
            }
            $resolvedMedia[$key] = [
                'url' => $this->publicMediaUrl($path),
                'alt' => $alt,
                'available' => $this->mediaFileExists($path),
            ];
        }
        $payload['media'] = $resolvedMedia;
        return $payload;
    }

    /**
     * @return array{sections: list<array<string, mixed>>, intro: string}
     */
    public function contact(): array
    {
        $sections = $this->decodeJsonFile($this->contentRoot . '/contact.json');
        if (!array_is_list($sections)) {
            throw new RuntimeException('contact.json must be a JSON array of sections.');
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $normalized[] = $this->normalizeContactSection($section);
        }

        return [
            'intro' => 'Global offices and regional sales teams ready to support your project.',
            'sections' => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function normalizeContactSection(array $section): array
    {
        $out = [
            'id' => (string) ($section['id'] ?? ''),
            'title' => (string) ($section['title'] ?? ''),
        ];
        if (isset($section['offices']) && is_array($section['offices'])) {
            $out['offices'] = $this->normalizeOffices($section['offices']);
        }
        if (isset($section['subsections']) && is_array($section['subsections'])) {
            $subs = [];
            foreach ($section['subsections'] as $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $subs[] = [
                    'title' => (string) ($sub['title'] ?? ''),
                    'offices' => isset($sub['offices']) && is_array($sub['offices'])
                        ? $this->normalizeOffices($sub['offices'])
                        : [],
                ];
            }
            $out['subsections'] = $subs;
        }
        return $out;
    }

    /**
     * @param list<mixed> $offices
     * @return list<array<string, mixed>>
     */
    private function normalizeOffices(array $offices): array
    {
        $out = [];
        foreach ($offices as $office) {
            if (!is_array($office)) {
                continue;
            }
            $location = isset($office['location']) && is_string($office['location'])
                ? trim($office['location'])
                : '';
            $email = isset($office['email']) && is_string($office['email'])
                ? trim($office['email'])
                : '';
            if ($location === '' || $email === '') {
                continue;
            }
            $row = [
                'location' => $location,
                'email' => $email,
            ];
            if (isset($office['phone']) && is_string($office['phone']) && trim($office['phone']) !== '') {
                $row['phone'] = trim($office['phone']);
            }
            if (isset($office['address']) && is_string($office['address']) && trim($office['address']) !== '') {
                $row['address'] = trim($office['address']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Public content file missing: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Public content file unreadable: ' . $path);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Public content file is not valid JSON: ' . $path);
        }
        return $decoded;
    }

    private function publicMediaUrl(string $path): string
    {
        $trimmed = '/' . ltrim($path, '/');
        return $trimmed;
    }

    private function mediaFileExists(string $path): bool
    {
        $basename = basename($path);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return false;
        }
        return is_file($this->publicMediaRoot . DIRECTORY_SEPARATOR . $basename);
    }
}
