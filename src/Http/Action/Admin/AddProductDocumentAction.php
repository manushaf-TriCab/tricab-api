<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\AddProductDocumentRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\AddProductDocumentCommand;
use TriCab\Core\Product\Application\Service\AddProductDocumentService;

final class AddProductDocumentAction
{
    public function __construct(
        private readonly AddProductDocumentService $addDocument,
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
            $input = AddProductDocumentRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $created = $this->transaction->run(fn () => $this->addDocument->execute(
                $actor,
                new AddProductDocumentCommand(
                    productPublicId: $productPublicId,
                    documentTypeCode: $input->documentTypeCode,
                    title: $input->title,
                    storagePath: $input->storagePath,
                    accessCode: $input->accessCode,
                    versionLabel: $input->versionLabel,
                    languageCode: $input->languageCode,
                    regionPublicId: $input->regionPublicId,
                    marketPublicId: $input->marketPublicId,
                    effectiveFromUtc: $input->effectiveFromUtc,
                    effectiveToUtc: $input->effectiveToUtc,
                    displayOrder: $input->displayOrder,
                    publicUrl: $input->publicUrl,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::documentSummary($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
