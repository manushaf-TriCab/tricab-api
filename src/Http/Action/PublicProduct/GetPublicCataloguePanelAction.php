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
use TriCab\Api\Http\Response\PayloadEtag;
use TriCab\Api\Http\Response\RegionScopedCacheHeaders;
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
            $region = $this->regions->resolve($request, QueryParams::stringOrNull($query, 'regionCode'));
            $regionCode = $region['region']->code;
            $includeProducts = QueryParams::boolOrNull($query, 'includeProducts') ?? false;
            $cacheKey = $this->cache->cataloguePanelKey([
                'tabSlug' => $tabSlug,
                'regionCode' => $regionCode,
                'includeProducts' => $includeProducts ? '1' : '0',
            ]);

            $cacheHeaders = RegionScopedCacheHeaders::for($region['source'], $request, $this->images);
            $origin = $this->stableUrls->originFor($request);
            [$entry, $hit] = $this->cache->rememberCatalogue($cacheKey, function () use ($tabSlug, $regionCode, $includeProducts): array {
                $this->metrics->recordCatalogueCacheHit(false);
                $built = CatalogueDtoSerializer::publicPanel(
                    $this->catalogue->getPanel($tabSlug, $regionCode, $includeProducts),
                );

                return ['etag' => PayloadEtag::for($built), 'data' => $built];
            });
            $this->metrics->recordCatalogueCacheHit($hit);
            /** @var array<string, mixed> $payload */
            $payload = is_array($entry['data'] ?? null) ? $entry['data'] : [];

            $presented = $this->images->signPublicDocument($payload, $origin !== '' ? $origin : null);
            $etag = RegionScopedCacheHeaders::etag($regionCode, $presented);
            if (PayloadEtag::matches($request, $etag)) {
                return $this->responder->notModified(
                    $etag,
                    $cacheHeaders['Cache-Control'],
                    array_diff_key($cacheHeaders, ['Cache-Control' => true]),
                );
            }

            return $this->responder->success($presented, 200, [
                'ETag' => $etag,
                ...$cacheHeaders,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
