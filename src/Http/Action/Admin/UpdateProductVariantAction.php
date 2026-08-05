<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\UpdateProductVariantRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\UpdateProductVariantCommand;
use TriCab\Core\Product\Application\Service\UpdateProductVariantService;

final class UpdateProductVariantAction
{
    public function __construct(
        private readonly UpdateProductVariantService $updateVariant,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $variantPublicId = RouteParams::require($request, 'variantPublicId');
            $input = UpdateProductVariantRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $result = $this->transaction->run(fn () => $this->updateVariant->execute(
                $actor,
                new UpdateProductVariantCommand(
                    publicId: $variantPublicId,
                    expectedRowVersion: $input->expectedRowVersion,
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

            return $this->responder->success(ProductManagementDtoSerializer::mutationResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
