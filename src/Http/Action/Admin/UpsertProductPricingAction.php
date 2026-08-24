<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\UpsertPricingRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\UpsertProductPricingCommand;
use TriCab\Core\Product\Application\Service\UpsertProductPricingService;

final class UpsertProductPricingAction
{
    public function __construct(
        private readonly UpsertProductPricingService $upsertPricing,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $productPublicId = RouteParams::require($request, 'productPublicId');
            $input = UpsertPricingRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $saved = $this->transaction->run(fn () => $this->upsertPricing->execute(
                $actor,
                new UpsertProductPricingCommand(
                    productPublicId: $productPublicId,
                    currencyCode: $input->currencyCode,
                    baseCost: $input->baseCost,
                    minimumQuantity: $input->minimumQuantity,
                    priceListPublicId: $input->priceListPublicId,
                    regionPublicId: $input->regionPublicId,
                    marketPublicId: $input->marketPublicId,
                    discountPercent: $input->discountPercent,
                    taxInclusive: $input->taxInclusive,
                    effectiveFromUtc: $input->effectiveFromUtc,
                    effectiveToUtc: $input->effectiveToUtc,
                    variantPublicId: $input->variantPublicId,
                    priceSourceCode: $input->priceSourceCode,
                    createRevision: $input->createRevision,
                    pricingPublicId: $input->pricingPublicId,
                    expectedRowVersion: $input->expectedRowVersion,
                    unitOfMeasurePublicId: $input->unitOfMeasurePublicId,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::pricingSummary($saved));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
