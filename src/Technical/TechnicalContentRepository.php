<?php

declare(strict_types=1);

namespace TriCab\Api\Technical;

use RuntimeException;
use TriCab\Api\Content\PublicMediaDirectory;
use TriCab\Api\Support\JsonValue;

/**
 * Version-controlled Technical module content (manifest, articles, lookup tables).
 * No database — shared read model for web and mobile.
 */
final class TechnicalContentRepository
{
    public function __construct(
        private readonly string $contentRoot,
        private readonly string $publicMediaRoot,
    ) {
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        $root = rtrim($projectRoot, DIRECTORY_SEPARATOR);

        return new self(
            $root . '/resources/technical/v1',
            PublicMediaDirectory::forProjectRoot($root) . '/media/technical',
        );
    }

    /**
     * @return array{
     *   version: string,
     *   title: string,
     *   description: string,
     *   sections: list<array<string, mixed>>
     * }
     */
    public function sections(): array
    {
        $manifest = $this->decodeJsonObject($this->contentRoot . '/manifest.json');
        $sections = $manifest['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)) {
            throw new RuntimeException('Technical manifest sections must be a JSON array.');
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $normalized[] = $this->normalizeSection(JsonValue::toObject($section));
        }

        usort(
            $normalized,
            static fn (array $a, array $b): int => (JsonValue::int($a, 'order')) <=> (JsonValue::int($b, 'order')),
        );

        return [
            'version' => JsonValue::string($manifest, 'version', '1'),
            'title' => JsonValue::string($manifest, 'title', 'Technical'),
            'description' => JsonValue::string($manifest, 'description'),
            'sections' => $normalized,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function article(string $slug): array
    {
        $key = $this->safeKey($slug);
        $path = $this->contentRoot . '/articles/' . $key . '.json';
        $article = $this->decodeJsonObject($path);
        $blocks = $article['blocks'] ?? [];
        if (!is_array($blocks)) {
            $blocks = [];
        }

        $resolvedBlocks = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $resolvedBlocks[] = $this->resolveArticleBlock(JsonValue::toObject($block));
        }

        return [
            'key' => JsonValue::string($article, 'key', $key),
            'slug' => JsonValue::string($article, 'slug', $key),
            'title' => JsonValue::string($article, 'title'),
            'intro' => JsonValue::string($article, 'intro'),
            'blocks' => $resolvedBlocks,
            'source' => is_array($article['source'] ?? null) ? $article['source'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function table(string $key): array
    {
        $safe = $this->safeKey($key);
        $path = $this->contentRoot . '/tables/' . $safe . '.json';
        $table = $this->decodeJsonObject($path);

        return $table;
    }

    /**
     * Educational product-coding packs (industrial | sbo | accessories).
     *
     * @return array<string, mixed>
     */
    public function productCoding(string $mode): array
    {
        $allowed = ['industrial', 'sbo', 'accessories'];
        if (!in_array($mode, $allowed, true)) {
            throw new RuntimeException('Invalid Technical content key.');
        }

        $path = $this->contentRoot . '/product-coding/' . $mode . '.json';

        return $this->decodeJsonObject($path);
    }

    /**
     * Document library catalog. When $category is set, returns that family only
     * (matched by family key or documentCategory). Otherwise returns the full
     * catalog summary including all families.
     *
     * @return array<string, mixed>
     */
    public function documents(?string $category = null): array
    {
        $catalog = $this->decodeJsonObject($this->contentRoot . '/documents/catalog.json');
        $familiesRaw = $catalog['families'] ?? null;
        if (!is_array($familiesRaw) || array_is_list($familiesRaw)) {
            throw new RuntimeException('Technical documents catalog families must be a JSON object.');
        }

        $normalizedCategory = $category !== null ? trim($category) : '';
        if ($normalizedCategory !== '') {
            $family = $this->findDocumentFamily(JsonValue::toObject($familiesRaw), $normalizedCategory);
            if ($family === null) {
                throw new RuntimeException('Technical document category missing: ' . $normalizedCategory);
            }

            $items = isset($family['items']) && is_array($family['items']) ? $family['items'] : [];

            $out = [
                'version' => JsonValue::string($catalog, 'version', '1'),
                'category' => $normalizedCategory,
                'key' => JsonValue::string($family, '_key', $normalizedCategory),
                'title' => JsonValue::string($family, 'title'),
                'section' => JsonValue::string($family, 'section'),
                'documentCategory' => JsonValue::string($family, 'documentCategory', $normalizedCategory),
                'items' => $items,
            ];
            if (isset($family['inventory']) && is_array($family['inventory'])) {
                $out['inventory'] = $family['inventory'];
            }

            return $out;
        }

        /** @var array<string, array<string, mixed>> $families */
        $families = [];
        foreach ($familiesRaw as $key => $familyRaw) {
            if (!is_string($key) || !is_array($familyRaw)) {
                continue;
            }
            $family = JsonValue::toObject($familyRaw);
            $items = JsonValue::objectList($family, 'items');
            $entry = [
                'key' => $key,
                'title' => JsonValue::string($family, 'title'),
                'section' => JsonValue::string($family, 'section'),
                'documentCategory' => JsonValue::string($family, 'documentCategory', $key),
                'itemCount' => count($items),
                'items' => $items,
            ];
            if (isset($family['inventory']) && is_array($family['inventory'])) {
                $entry['inventory'] = $family['inventory'];
            }
            $families[$key] = $entry;
        }

        return [
            'version' => JsonValue::string($catalog, 'version', '1'),
            'architecture' => JsonValue::string($catalog, 'architecture'),
            'families' => $families,
            'totals' => is_array($catalog['totals'] ?? null) ? $catalog['totals'] : [],
            'dryRunPolicy' => JsonValue::string($catalog, 'dryRunPolicy'),
        ];
    }

    /**
     * @param array<string, mixed> $families
     * @return array<string, mixed>|null
     */
    private function findDocumentFamily(array $families, string $category): ?array
    {
        if (is_array($families[$category] ?? null)) {
            $family = JsonValue::toObject($families[$category]);
            $family['_key'] = $category;

            return $family;
        }

        foreach ($families as $key => $familyRaw) {
            if (!is_array($familyRaw)) {
                continue;
            }
            $family = JsonValue::toObject($familyRaw);
            if (JsonValue::string($family, 'documentCategory') === $category) {
                $family['_key'] = $key;

                return $family;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function normalizeSection(array $section): array
    {
        $itemsRaw = $section['items'] ?? [];
        /** @var list<array<string, mixed>> $items */
        $items = [];
        if (is_array($itemsRaw)) {
            foreach ($itemsRaw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                // Never expose intentionally disabled Industrial Current Ratings.
                if (($item['id'] ?? '') === 'current-ratings' || ($item['enabled'] ?? true) === false) {
                    continue;
                }
                $items[] = $this->normalizeItem(JsonValue::toObject($item));
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => (JsonValue::int($a, 'order')) <=> (JsonValue::int($b, 'order')),
        );

        $out = [
            'id' => JsonValue::string($section, 'id'),
            'slug' => JsonValue::string($section, 'slug', JsonValue::string($section, 'id')),
            'title' => JsonValue::string($section, 'title'),
            'order' => JsonValue::int($section, 'order'),
            'items' => $items,
        ];

        if (isset($section['visibilityPolicy']) && is_string($section['visibilityPolicy'])) {
            $out['visibilityPolicy'] = $section['visibilityPolicy'];
        }
        if (isset($section['visibilityNote']) && is_string($section['visibilityNote'])) {
            $out['visibilityNote'] = $section['visibilityNote'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $out = [
            'id' => JsonValue::string($item, 'id'),
            'slug' => JsonValue::string($item, 'slug', JsonValue::string($item, 'id')),
            'title' => JsonValue::string($item, 'title'),
            'description' => JsonValue::string($item, 'description'),
            'type' => JsonValue::string($item, 'type', 'ARTICLE'),
            'iconKey' => JsonValue::string($item, 'iconKey', 'Wrench'),
            'order' => JsonValue::int($item, 'order'),
            'enabled' => JsonValue::bool($item, 'enabled', true),
            'status' => JsonValue::string($item, 'status', 'COMING_SOON'),
            'keywords' => [],
        ];

        if (isset($item['keywords']) && is_array($item['keywords'])) {
            $keywords = [];
            foreach ($item['keywords'] as $keyword) {
                if (is_string($keyword) && $keyword !== '') {
                    $keywords[] = $keyword;
                }
            }
            $out['keywords'] = $keywords;
        }

        foreach (['contentKey', 'tableKey', 'documentCategory', 'existingModule', 'codingMode'] as $optional) {
            if (isset($item[$optional]) && is_string($item[$optional]) && $item[$optional] !== '') {
                $out[$optional] = $item[$optional];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function resolveArticleBlock(array $block): array
    {
        $type = JsonValue::string($block, 'type');
        if ($type === 'steps' && isset($block['items']) && is_array($block['items'])) {
            $items = [];
            foreach ($block['items'] as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $stepData = JsonValue::toObject($step);
                $row = [
                    'number' => JsonValue::int($stepData, 'number'),
                    'body' => JsonValue::string($stepData, 'body'),
                ];
                if (is_array($stepData['image'] ?? null)) {
                    $resolved = $this->resolveMediaRef(JsonValue::toObject($stepData['image']));
                    if ($resolved !== null) {
                        $row['image'] = $resolved;
                    }
                }
                $items[] = $row;
            }

            return ['type' => 'steps', 'items' => $items];
        }

        if ($type === 'image') {
            $resolved = $this->resolveMediaRef($block);
            if ($resolved === null) {
                return $block;
            }

            $out = array_merge($block, $resolved);
            $out['type'] = 'image';

            return $out;
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $ref
     * @return array{url: string, alt: string, available: bool, path?: string, caption?: string}|null
     */
    private function resolveMediaRef(array $ref): ?array
    {
        $path = isset($ref['path']) && is_string($ref['path']) ? $ref['path'] : '';
        $url = isset($ref['url']) && is_string($ref['url']) ? $ref['url'] : '';
        $alt = isset($ref['alt']) && is_string($ref['alt']) ? $ref['alt'] : '';
        if ($path === '' && $url === '') {
            return null;
        }
        if ($path !== '' && $url === '') {
            $url = '/media/technical/' . ltrim($path, '/');
        }
        $out = [
            'url' => $url,
            'alt' => $alt,
            'available' => $path !== '' ? $this->mediaFileExists($path) : true,
        ];
        if ($path !== '') {
            $out['path'] = $path;
        }
        if (isset($ref['caption']) && is_string($ref['caption']) && $ref['caption'] !== '') {
            $out['caption'] = $ref['caption'];
        }

        return $out;
    }

    private function mediaFileExists(string $path): bool
    {
        $normalized = str_replace(['..', '\\'], '', $path);
        $full = $this->publicMediaRoot . DIRECTORY_SEPARATOR . ltrim($normalized, '/');

        return is_file($full);
    }

    private function safeKey(string $key): string
    {
        $trimmed = trim($key);
        if ($trimmed === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $trimmed)) {
            throw new RuntimeException('Invalid Technical content key.');
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Technical content file missing: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Technical content file unreadable: ' . $path);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Technical content file must be a JSON object: ' . $path);
        }

        return JsonValue::toObject($decoded);
    }
}
