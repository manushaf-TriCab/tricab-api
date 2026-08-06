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
use TriCab\Core\Product\Application\Service\GetPublicProductService;

final class GetPublicProductDocumentsAction
{
    public function __construct(
        private readonly GetPublicProductService $getPublicProduct,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $slug = RouteParams::require($request, 'slug');
            $query = QueryParams::normalize($request->getQueryParams());
            $regionCode = QueryParams::stringOrNull($query, 'regionCode');
            $detail = $this->getPublicProduct->bySlug($slug, $regionCode);

            return $this->responder->success([
                'items' => array_map(ProductManagementDtoSerializer::publicDocumentSummary(...), $detail->documents),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
