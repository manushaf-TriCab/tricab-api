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
use TriCab\Core\Product\Application\Service\SearchProductsService;

final class SearchPublicProductsAction
{
    public function __construct(
        private readonly SearchProductsService $searchProducts,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $criteria = ProductSearchQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->searchProducts->execute($criteria);

            return $this->responder->success(ProductManagementDtoSerializer::searchListResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
