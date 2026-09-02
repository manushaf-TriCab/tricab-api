<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Public\Product\ProductCodeSearchQuery;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Service\SearchProductCodesService;

/**
 * GET /api/v1/product-codes/search — structured search over the nine-segment
 * product-code universe, beside the product-level /api/v1/product-search.
 */
final class SearchPublicProductCodesAction
{
    public function __construct(
        private readonly SearchProductCodesService $searchProductCodes,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $criteria = ProductCodeSearchQuery::fromQueryParams($query);
            $result = $this->searchProductCodes->execute($criteria);

            return $this->responder->success(
                ProductManagementDtoSerializer::codeSearchListResult($result),
            );
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
