<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;

/**
 * Clears lookup/catalogue ApplicationCache entries after successful mutating admin requests.
 */
final class ReferenceDataCacheInvalidationMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private const LOOKUP_PREFIXES = [
        '/api/v1/admin/product-families',
        '/api/v1/admin/product-categories',
        '/api/v1/admin/product-series',
        '/api/v1/admin/specification-groups',
        '/api/v1/admin/specification-units',
        '/api/v1/admin/specification-definitions',
        '/api/v1/admin/specification-options',
    ];

    /** @var list<string> */
    private const CATALOGUE_PREFIXES = [
        '/api/v1/admin/catalogue-tabs',
        '/api/v1/admin/catalogue-nodes',
    ];

    /**
     * Catalogue panel / node / tree payloads embed product names, slugs, codes, thumbnails,
     * publication state and regional visibility, so these writes also drop the catalogue cache.
     *
     * @var list<string>
     */
    private const PRODUCT_CATALOGUE_PREFIXES = [
        '/api/v1/admin/products',
        '/api/v1/admin/product-styles',
        '/api/v1/admin/product-variants',
        '/api/v1/admin/product-media',
        '/api/v1/admin/product-codes',
        '/api/v1/admin/product-families',
        '/api/v1/admin/product-categories',
        '/api/v1/admin/product-series',
        '/api/v1/admin/product-migration',
        '/api/v1/admin/legacy-migration',
    ];

    public function __construct(
        private readonly ReferenceDataCache $cache,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $method = strtoupper($request->getMethod());
        if ($method === 'GET' || $method === 'OPTIONS' || $method === 'HEAD') {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }

        $path = $request->getUri()->getPath();
        if ($path === '/api/v1/admin/regions' || str_starts_with($path, '/api/v1/admin/regions/')) {
            $this->cache->invalidateRegions();
            $this->cache->invalidateCatalogue();
        }
        if ($this->matches($path, self::LOOKUP_PREFIXES)) {
            $this->cache->invalidateLookups();
        }
        if (
            $this->matches($path, self::CATALOGUE_PREFIXES)
            || $this->matches($path, self::PRODUCT_CATALOGUE_PREFIXES)
            || str_contains($path, '/catalogue-placements')
        ) {
            $this->cache->invalidateCatalogue();
        }
        if ($this->isProductRegionMutation($path)) {
            $this->cache->invalidateLookups();
            $this->cache->invalidateRegions();
            $this->cache->invalidateCatalogue();
        }

        return $response;
    }

    private function isProductRegionMutation(string $path): bool
    {
        if (!preg_match('#^/api/v1/admin/products/[^/]+/(markets|regions)$#', $path)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $prefixes
     */
    private function matches(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
