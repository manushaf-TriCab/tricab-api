<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\CompareLegacyBaseCostCommand;
use TriCab\Core\Product\Application\Service\CompareLegacyBaseCostService;

final class CompareLegacyBaseCostAction
{
    public function __construct(
        private readonly CompareLegacyBaseCostService $compare,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $productPublicId = RouteParams::require($request, 'productPublicId');
            $query = $request->getQueryParams();
            $variantPublicId = is_string($query['variantPublicId'] ?? null) ? $query['variantPublicId'] : '';
            if ($variantPublicId === '') {
                throw new \InvalidArgumentException('variantPublicId is required.');
            }
            $dbco = (int) ($query['legacyDebtorCompanyCode'] ?? 2507);
            $actor = AdminActorFactory::fromRequest($request);

            $comparison = $this->compare->execute($actor, new CompareLegacyBaseCostCommand(
                productPublicId: $productPublicId,
                variantPublicId: $variantPublicId,
                legacyDebtorCompanyCode: $dbco,
                priceListPublicId: is_string($query['priceListPublicId'] ?? null) ? $query['priceListPublicId'] : null,
            ));

            return $this->responder->success(ProductManagementDtoSerializer::legacyBaseCostComparison($comparison));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
