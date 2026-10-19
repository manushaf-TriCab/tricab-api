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
use TriCab\Api\Product\Catalogue\CatalogueNavigationImageSigner;
use TriCab\Api\Product\Media\StableMediaUrlSigner;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Core\Product\Application\Service\GetPublicCatalogueService;

final class GetPublicCataloguePanelAction
{
    public function __construct(
        private readonly GetPublicCatalogueService $catalogue,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
        private readonly ReferenceDataCache $cache,
        private readonly RequestPerformanceMetrics $metrics,
        private readonly RegionPreferenceResolver $regions,
        private readonly CatalogueNavigationImageSigner $images,
        private readonly StableMediaUrlSigner $stableUrls,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $tabSlug = QueryParams::stringOrNull($query, 'tabSlug');
            $regionCode = $this->regions->resolveCode($request, QueryParams::stringOrNull($query, 'regionCode'));
            $includeProducts = QueryParams::boolOrNull($query, 'includeProducts') ?? false;
            $cacheKey = $this->cache->cataloguePanelKey([
                'tabSlug' => $tabSlug,
                'regionCode' => $regionCode,
                'includeProducts' => $includeProducts ? '1' : '0',
            ]);

            $cacheControl = $this->images->cacheControl(true);
            $origin = $this->stableUrls->originFor($request);
            $cached = $this->cache->getCatalogue($cacheKey);
            if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
                $this->metrics->recordCatalogueCacheHit(true);
                /** @var array<string, mixed> $payload */
                $payload = $cached['data'];
            } else {
                $this->metrics->recordCatalogueCacheHit(false);
                $payload = CatalogueDtoSerializer::publicPanel(
                    $this->catalogue->getPanel($tabSlug, $regionCode, $includeProducts),
                );
                $this->cache->setCatalogue($cacheKey, [
                    'etag' => self::etagFor($payload),
                    'data' => $payload,
                ]);
            }

            // The ETag covers the payload as sent, so a 304 can never keep an expired read signature alive.
            $presented = $this->images->signPublicDocument($payload, $origin !== '' ? $origin : null);
            $etag = self::etagFor($presented);
            if ($this->etagMatches($request, $etag)) {
                return $this->responder->notModified($etag, $cacheControl);
            }

            return $this->responder->success($presented, 200, [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function etagFor(array $payload): string
    {
        return '"' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
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
