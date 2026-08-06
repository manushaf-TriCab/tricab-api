<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\CreateProductRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\CreateProductCommand;
use TriCab\Core\Product\Application\Service\CreateProductService;

final class CreateProductAction
{
    public function __construct(
        private readonly CreateProductService $createProduct,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = CreateProductRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            $created = $this->transaction->run(fn () => $this->createProduct->execute(
                $actor,
                new CreateProductCommand(
                    productName: $input->productName,
                    internalName: $input->internalName,
                    internalCode: $input->internalCode,
                    shortDescription: $input->shortDescription,
                    fullDescription: $input->fullDescription,
                    legacyProductId: $input->legacyProductId,
                    erpReference: $input->erpReference,
                    isFeatured: $input->isFeatured,
                    familyPublicId: $input->familyPublicId,
                    categoryPublicId: $input->categoryPublicId,
                    seriesPublicId: $input->seriesPublicId,
                    statusCode: $input->statusCode,
                    lifecycleStatusCode: $input->lifecycleStatusCode,
                    publishAtUtc: $input->publishAtUtc,
                    unpublishAtUtc: $input->unpublishAtUtc,
                    discontinueAtUtc: $input->discontinueAtUtc,
                    isActive: $input->isActive,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::mutationResult($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
