<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

/**
 * Maps website-only catalogue/certificate paths onto API-owned /media URLs.
 *
 * Stored CatalogueNodes.ImageUrl values stay on /assets (and the /assests typo)
 * so existing web clients keep working. Public DTOs rewrite to /media/... when
 * the API has a copy of the file.
 */
final class CatalogueWebsiteAssetPath
{
    public const CATALOGUE_MEDIA_PREFIX = '/media/catalogue/';
    public const CERTIFICATE_MEDIA_PREFIX = '/media/certificates/';

    /**
     * @return array{
     *   kind: 'catalogue'|'certificate',
     *   basename: string,
     *   sourceRelative: string,
     *   target: string
     * }|null
     */
    public static function websiteStaticTarget(?string $storedUrl): ?array
    {
        if ($storedUrl === null) {
            return null;
        }
        $trimmed = trim($storedUrl);
        if ($trimmed === '' || preg_match('#^https?://#i', $trimmed) === 1 || str_starts_with($trimmed, 'data:')) {
            return null;
        }

        $normalized = self::normalize($trimmed);
        if (str_starts_with($normalized, '/assets/')) {
            $basename = self::safeBasename($normalized);
            if ($basename === null) {
                return null;
            }

            return [
                'kind' => 'catalogue',
                'basename' => $basename,
                'sourceRelative' => '/assets/' . $basename,
                'target' => self::CATALOGUE_MEDIA_PREFIX . $basename,
            ];
        }

        if (str_starts_with($normalized, '/certificates/')) {
            $basename = self::safeBasename($normalized);
            if ($basename === null) {
                return null;
            }

            return [
                'kind' => 'certificate',
                'basename' => $basename,
                'sourceRelative' => '/certificates/' . $basename,
                'target' => self::CERTIFICATE_MEDIA_PREFIX . $basename,
            ];
        }

        return null;
    }

    /**
     * @param callable(string, string): bool $mediaExists kind, basename
     */
    public static function toPublicUrl(?string $storedUrl, callable $mediaExists): ?string
    {
        if ($storedUrl === null) {
            return null;
        }
        $trimmed = trim($storedUrl);
        if ($trimmed === '') {
            return null;
        }

        $target = self::websiteStaticTarget($trimmed);
        if ($target === null) {
            return $trimmed;
        }

        if ($mediaExists($target['kind'], $target['basename'])) {
            return $target['target'];
        }

        return $target['sourceRelative'];
    }

    public static function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));

        return str_replace('/assests/', '/assets/', $normalized);
    }

    private static function safeBasename(string $path): ?string
    {
        $basename = basename($path);
        if ($basename === '' || $basename === '.' || $basename === '..' || str_contains($basename, '/')) {
            return null;
        }

        return $basename;
    }
}
