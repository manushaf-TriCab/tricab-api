<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\InitiateProductDocumentUploadRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\InitiateProductDocumentUploadCommand;
use TriCab\Core\Product\Application\Service\InitiateProductDocumentUploadService;

final class InitiateProductDocumentUploadAction
{
    public function __construct(
        private readonly InitiateProductDocumentUploadService $initiate,
        private readonly Settings $settings,
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
            $input = InitiateProductDocumentUploadRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $result = $this->transaction->run(fn () => $this->initiate->execute(
                $actor,
                new InitiateProductDocumentUploadCommand(
                    productPublicId: $productPublicId,
                    documentTypeCode: $input->documentTypeCode,
                    title: $input->title,
                    accessCode: $input->accessCode,
                    originalFileName: $input->originalFileName,
                    contentType: $input->contentType,
                    byteSize: $input->byteSize,
                    storageContainer: $this->settings->blobContainerProducts,
                    versionLabel: $input->versionLabel,
                    languageCode: $input->languageCode,
                    displayOrder: $input->displayOrder,
                    uploadExpiresInSeconds: $this->settings->blobUploadSasTtlSeconds,
                    isCad: $input->isCad,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::uploadSessionResult($result), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
