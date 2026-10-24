<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Catalogue;

use TriCab\Api\Content\PublicMediaDirectory;
use TriCab\Core\Product\Application\Service\CatalogueWebsiteAssetPath;

/**
 * Filesystem check for API-owned copies of website catalogue/certificate images.
 *
 * Stored DB paths stay on /assets and /certificates. Public DTOs rewrite to
 * /media/... only when the matching file exists under public/.
 */
final class CatalogueStaticMediaResolver
{
    private static ?self $instance = null;

    public function __construct(
        private readonly string $publicDir,
    ) {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(PublicMediaDirectory::forProjectRoot(dirname(__DIR__, 3)));
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function mediaExists(string $kind, string $basename): bool
    {
        if ($basename === '' || str_contains($basename, '/') || str_contains($basename, '\\')) {
            return false;
        }
        $subdir = $kind === 'certificate' ? 'media/certificates' : 'media/catalogue';
        $path = $this->publicDir . '/' . $subdir . '/' . $basename;

        return is_file($path);
    }

    public function toPublicUrl(?string $storedUrl): ?string
    {
        return CatalogueWebsiteAssetPath::toPublicUrl($storedUrl, $this->mediaExists(...));
    }
}
