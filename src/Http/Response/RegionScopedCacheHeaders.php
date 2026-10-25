<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Response;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Product\Catalogue\CatalogueNavigationImageSigner;

/**
 * HTTP cache headers for public catalogue payloads whose content depends only on the region.
 *
 * Only a region named in the URL may be cached publicly. A region taken from the cookie or the
 * default changes without the URL changing, so those responses are private, must be
 * revalidated on every use, and vary by Cookie. Requests carrying credentials are never
 * declared public, even though the payload does not depend on the caller.
 */
final class RegionScopedCacheHeaders
{
    /**
     * @param 'explicit'|'cookie'|'default' $regionSource
     * @return array{Cache-Control: string, Vary?: string}
     */
    public static function for(
        string $regionSource,
        ServerRequestInterface $request,
        CatalogueNavigationImageSigner $images,
    ): array {
        if ($regionSource !== 'explicit') {
            $private = $images->cacheControl(false);

            return [
                'Cache-Control' => str_contains($private, 'no-store') ? $private : 'private, no-cache',
                'Vary' => 'Cookie',
            ];
        }

        return ['Cache-Control' => $images->cacheControl(!$request->hasHeader('Authorization'))];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function etag(string $regionCode, array $payload): string
    {
        return PayloadEtag::for(['regionCode' => strtoupper($regionCode), 'payload' => $payload]);
    }
}
