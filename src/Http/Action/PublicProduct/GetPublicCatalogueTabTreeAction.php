<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\CatalogueDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Http\Response\PayloadEtag;
use TriCab\Api\Http\Response\RegionScopedCacheHeaders;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Catalogue\CatalogueNavigationImageSigner;
use TriCab\Api\Product\Media\StableMediaUrlSigner;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Core\Product\Application\Service\GetPublicCatalogueService;

final class GetPublicCatalogueTabTreeAction
{
    public function __construct(
        private readonly GetPublicCatalogueService $catalogue,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
        private readonly RegionPreferenceResolver $regions,
        private readonly CatalogueNavigationImageSigner $images,
        private readonly StableMediaUrlSigner $stableUrls,
        private readonly ReferenceDataCache $cache,
        private readonly RequestPerformanceMetrics $metrics,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $tabSlug = RouteParams::require($request, 'tabSlug');
            $query = QueryParams::normalize($request->getQueryParams());
            $region = $this->regions->resolve($request, QueryParams::stringOrNull($query, 'regionCode'));
            $regionCode = $region['region']->code;
            $includeProducts = QueryParams::boolOrNull($query, 'includeProducts') ?? false;
            $cacheKey = $this->cache->catalogueKey('tree', [
                'tabSlug' => $tabSlug,
                'regionCode' => $regionCode,
                'includeProducts' => $includeProducts ? '1' : '0',
            ]);

            [$entry, $hit] = $this->cache->rememberCatalogue($cacheKey, function () use ($tabSlug, $regionCode, $includeProducts): array {
                $this->metrics->recordCatalogueCacheHit(false);

                return [
                    'data' => [
                        'items' => CatalogueDtoSerializer::publicTreeList(
                            $this->catalogue->getTabTree($tabSlug, $regionCode, $includeProducts),
                        ),
                    ],
                ];
            });
            $this->metrics->recordCatalogueCacheHit($hit);
            /** @var array<string, mixed> $payload */
            $payload = is_array($entry['data'] ?? null) ? $entry['data'] : [];

            $cacheHeaders = RegionScopedCacheHeaders::for($region['source'], $request, $this->images);
            $presented = $this->images->signPublicDocument($payload, $this->originFor($request));
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

    private function originFor(ServerRequestInterface $request): ?string
    {
        $origin = $this->stableUrls->originFor($request);

        return $origin !== '' ? $origin : null;
    }
}
