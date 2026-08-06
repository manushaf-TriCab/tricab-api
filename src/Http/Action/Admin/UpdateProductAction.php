<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\UpdateProductRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\UpdateProductCommand;
use TriCab\Core\Product\Application\Service\UpdateProductService;

final class UpdateProductAction
{
    public function __construct(
        private readonly UpdateProductService $updateProduct,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $body = JsonRequestBody::requireObject($request);
            $input = UpdateProductRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            $updated = $this->transaction->run(fn () => $this->updateProduct->execute(
                $actor,
                new UpdateProductCommand(
                    publicId: $publicId,
                    expectedRowVersion: $input->expectedRowVersion,
                    productName: $input->productName,
                    internalName: $input->internalName,
                    internalCode: $input->internalCode,
                    shortDescription: $input->shortDescription,
                    fullDescription: $input->fullDescription,
                    legacyProductId: $input->legacyProductId,
                    erpReference: $input->erpReference,
                    isFeatured: $input->isFeatured,
                    publishAtUtc: $input->publishAtUtc,
                    unpublishAtUtc: $input->unpublishAtUtc,
                    discontinueAtUtc: $input->discontinueAtUtc,
                    isActive: $input->isActive,
                    updateClassification: $input->updateClassification,
                    familyPublicId: $input->familyPublicId,
                    categoryPublicId: $input->categoryPublicId,
                    seriesPublicId: $input->seriesPublicId,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::mutationResult($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
