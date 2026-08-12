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
use TriCab\Core\Product\Application\Service\GetProduct3DSpecificationSourceService;

final class GetProduct3DSpecificationSourceAction
{
    public function __construct(
        private readonly GetProduct3DSpecificationSourceService $service,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $productPublicId = RouteParams::require($request, 'productPublicId');
            $query = QueryParams::normalize($request->getQueryParams());
            $variantPublicId = QueryParams::stringOrNull($query, 'variantPublicId');
            $payload = $this->service->execute($productPublicId, $variantPublicId);

            return $this->responder->success($payload);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
