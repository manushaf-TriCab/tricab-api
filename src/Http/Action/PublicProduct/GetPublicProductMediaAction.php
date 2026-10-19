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
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Core\Product\Application\Service\GetPublicProductService;

final class GetPublicProductMediaAction
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
            $detail = $this->getPublicProduct->bySlug($slug, $regionCode);

            return $this->responder->success($this->stableUrls->stabilizePayload([
                'items' => array_map(
                    fn ($item) => ProductManagementDtoSerializer::publicMediaSummary($item, $this->devUrls),
                    $detail->media,
                ),
            ], $this->stableUrls->originFor($request)));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
