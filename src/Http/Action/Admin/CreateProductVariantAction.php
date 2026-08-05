<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\CreateProductVariantRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\CreateProductVariantCommand;
use TriCab\Core\Product\Application\Service\CreateProductVariantService;

final class CreateProductVariantAction
{
    public function __construct(
        private readonly CreateProductVariantService $createVariant,
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
            $input = CreateProductVariantRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $result = $this->transaction->run(fn () => $this->createVariant->execute(
                $actor,
                new CreateProductVariantCommand(
                    productPublicId: $productPublicId,
                    variantName: $input->variantName,
                    statusCode: $input->statusCode,
                    displayOrder: $input->displayOrder,
                    sku: $input->sku,
                    variantCode: $input->variantCode,
                    minimumOrderQuantity: $input->minimumOrderQuantity,
                    weightKg: $input->weightKg,
                    packagingDescription: $input->packagingDescription,
                    erpReference: $input->erpReference,
                    isActive: $input->isActive,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::mutationResult($result), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
