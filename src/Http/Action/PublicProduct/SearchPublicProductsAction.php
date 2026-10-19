<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Public\Product\ProductSearchQuery;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Media\StableMediaUrlSigner;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\Service\SearchProductsService;

final class SearchPublicProductsAction
{
    public function __construct(
        private readonly SearchProductsService $searchProducts,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
        private readonly RegionPreferenceResolver $regions,
        private readonly ProductDevMediaUrlResolver $devUrls,
        private readonly StableMediaUrlSigner $stableUrls,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $criteria = ProductSearchQuery::fromQueryParams($query, $this->regions->resolveCode(
                $request,
                QueryParams::stringOrNull($query, 'regionCode'),
            ));
            $result = $this->searchProducts->execute($criteria);

            return $this->responder->success($this->stableUrls->stabilizePayload(
                ProductManagementDtoSerializer::searchListResult($result, $this->devUrls),
                $this->stableUrls->originFor($request),
            ));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
