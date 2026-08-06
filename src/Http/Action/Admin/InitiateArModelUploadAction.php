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
use TriCab\Api\Http\Request\Admin\Product\InitiateArModelUploadRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\InitiateArModelUploadCommand;
use TriCab\Core\Product\Application\Service\InitiateArModelUploadService;

final class InitiateArModelUploadAction
{
    public function __construct(
        private readonly InitiateArModelUploadService $initiate,
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
            $input = InitiateArModelUploadRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $result = $this->transaction->run(fn () => $this->initiate->execute(
                $actor,
                new InitiateArModelUploadCommand(
                    productPublicId: $productPublicId,
                    title: $input->title,
                    originalFileName: $input->originalFileName,
                    contentType: $input->contentType,
                    byteSize: $input->byteSize,
                    storageContainer: $this->settings->blobContainerProducts,
                    isPrimary: $input->isPrimary,
                    uploadExpiresInSeconds: $this->settings->blobUploadSasTtlSeconds,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::uploadSessionResult($result), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
