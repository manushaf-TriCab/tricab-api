<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Media\StableMediaUrlSigner;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\Service\GetPublicProductService;

/**
 * Returns only the public threeD payload (same shape as the embedded product detail one).
 * Responses carry freshly signed URLs and must never be stored by shared caches.
 */
final class GetPublicProduct3DAction
{
    public function __construct(
        private readonly GetPublicProductService $getPublicProduct,
        private readonly ProductDevMediaUrlResolver $devUrls,
        private readonly RegionPreferenceResolver $regions,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
        private readonly StableMediaUrlSigner $stableUrls,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $slug = RouteParams::require($request, 'slug');
            $query = QueryParams::normalize($request->getQueryParams());
            $regionCode = $this->regions->resolveCode($request, QueryParams::stringOrNull($query, 'regionCode'));
            // threeD is already empty when PRODUCT_3D_ENABLED=false and filtered to published models.
            $detail = $this->getPublicProduct->bySlug($slug, $regionCode);

            return $this->responder->success(
                $this->stableUrls->stabilizePayload(
                    ProductManagementDtoSerializer::publicThreeD($detail->threeD, $this->devUrls),
                    $this->stableUrls->originFor($request),
                ),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (Throwable $exception) {
            return $this->errors->map($exception)->withHeader('Cache-Control', 'no-store');
        }
    }
}
