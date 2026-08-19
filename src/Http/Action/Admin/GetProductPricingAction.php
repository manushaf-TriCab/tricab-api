<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Service\GetProductBaseCostHistoryService;
use TriCab\Core\Product\Application\Service\GetProductPricingMatrixService;
use TriCab\Core\Product\Application\Service\GetProductPricingOverviewService;
use TriCab\Core\Product\Application\Service\GetProductPricingService;

final class GetProductPricingAction
{
    public function __construct(
        private readonly GetProductPricingService $pricing,
        private readonly GetProductPricingOverviewService $overview,
        private readonly GetProductPricingMatrixService $matrix,
        private readonly GetProductBaseCostHistoryService $history,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $path = rtrim($request->getUri()->getPath(), '/');

            if (str_ends_with($path, '/pricing/history')) {
                $query = $request->getQueryParams();
                $items = $this->history->execute(
                    $publicId,
                    is_string($query['variantPublicId'] ?? null) ? $query['variantPublicId'] : null,
                    is_string($query['priceListPublicId'] ?? null) ? $query['priceListPublicId'] : null,
                );

                return $this->responder->success(['items' => ProductManagementDtoSerializer::baseCostHistoryList($items)]);
            }

            $items = $this->pricing->execute($publicId);
            $overview = $this->overview->execute($publicId);
            $matrix = $this->matrix->execute($publicId);

            return $this->responder->success([
                'overview' => ProductManagementDtoSerializer::pricingOverview($overview),
                'matrix' => ProductManagementDtoSerializer::pricingMatrixList($matrix),
                'items' => ProductManagementDtoSerializer::pricingList($items),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
