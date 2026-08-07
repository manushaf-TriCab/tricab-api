<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\Service\GetAdminProductCustomerViewService;

final class GetProductCustomerViewAction
{
    public function __construct(
        private readonly GetAdminProductCustomerViewService $getCustomerView,
        private readonly ProductDevMediaUrlResolver $devUrls,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $query = QueryParams::normalize($request->getQueryParams());
            $regionCode = QueryParams::stringOrNull($query, 'regionCode');
            $detail = $this->getCustomerView->execute($publicId, $regionCode);

            return $this->responder->success(
                ProductManagementDtoSerializer::customerViewDetail($detail, $this->devUrls),
            );
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
