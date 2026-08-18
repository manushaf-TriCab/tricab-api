<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\DTO\ProductRegionAssignmentItem;
use TriCab\Core\Product\Application\Service\GetProductRegionsService;

final class GetProductRegionsAction
{
    public function __construct(
        private readonly GetProductRegionsService $getProductRegions,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $productPublicId = RouteParams::require($request, 'productPublicId');
            $regions = $this->getProductRegions->execute($productPublicId);

            return $this->responder->success([
                'availableRegions' => array_map(
                    static fn (ProductRegionAssignmentItem $item): array => [
                        'publicId' => $item->publicId,
                        'code' => $item->code,
                        'displayName' => $item->displayName,
                        'flag' => $item->flag,
                        'selected' => $item->selected,
                    ],
                    $regions,
                ),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
