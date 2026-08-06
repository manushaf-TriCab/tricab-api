<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\ProductListQuery;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Service\ListAdminProductsService;

final class ListProductsAction
{
    public function __construct(
        private readonly ListAdminProductsService $listProducts,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $criteria = ProductListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->listProducts->execute($criteria);

            return $this->responder->success(ProductManagementDtoSerializer::listResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
