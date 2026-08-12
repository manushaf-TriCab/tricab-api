<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\CatalogueDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Core\Product\Application\Service\GetPublicCatalogueService;

final class GetPublicCataloguePanelAction
{
    private const CACHE_CONTROL = 'public, max-age=60, stale-while-revalidate=120';

    public function __construct(
        private readonly GetPublicCatalogueService $catalogue,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
        private readonly ReferenceDataCache $cache,
        private readonly RequestPerformanceMetrics $metrics,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $tabSlug = QueryParams::stringOrNull($query, 'tabSlug');
            $regionCode = QueryParams::stringOrNull($query, 'regionCode');
            $includeProducts = QueryParams::boolOrNull($query, 'includeProducts') ?? false;
            $cacheKey = $this->cache->cataloguePanelKey([
                'tabSlug' => $tabSlug,
                'regionCode' => $regionCode,
                'includeProducts' => $includeProducts ? '1' : '0',
            ]);

            $cached = $this->cache->getCatalogue($cacheKey);
            if (is_array($cached) && isset($cached['etag'], $cached['data']) && is_string($cached['etag']) && is_array($cached['data'])) {
                $this->metrics->recordCatalogueCacheHit(true);
                if ($this->etagMatches($request, $cached['etag'])) {
                    return $this->responder->notModified($cached['etag']);
                }
                /** @var array<string, mixed> $cachedData */
                $cachedData = $cached['data'];

                return $this->responder->success($cachedData, 200, [
                    'ETag' => $cached['etag'],
                    'Cache-Control' => self::CACHE_CONTROL,
                ]);
            }

            $this->metrics->recordCatalogueCacheHit(false);
            $payload = CatalogueDtoSerializer::publicPanel(
                $this->catalogue->getPanel($tabSlug, $regionCode, $includeProducts),
            );
            $etag = '"' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
            $this->cache->setCatalogue($cacheKey, ['etag' => $etag, 'data' => $payload]);

            if ($this->etagMatches($request, $etag)) {
                return $this->responder->notModified($etag);
            }

            return $this->responder->success($payload, 200, [
                'ETag' => $etag,
                'Cache-Control' => self::CACHE_CONTROL,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private function etagMatches(ServerRequestInterface $request, string $etag): bool
    {
        $header = trim($request->getHeaderLine('If-None-Match'));
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $part) {
            if (trim($part) === $etag) {
                return true;
            }
        }

        return false;
    }
}
