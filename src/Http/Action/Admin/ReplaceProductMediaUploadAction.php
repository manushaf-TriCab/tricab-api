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
use TriCab\Api\Http\Request\Admin\Product\ReplaceProductMediaUploadRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\ReplaceProductMediaUploadCommand;
use TriCab\Core\Product\Application\Service\ReplaceProductMediaUploadService;

final class ReplaceProductMediaUploadAction
{
    public function __construct(
        private readonly ReplaceProductMediaUploadService $replace,
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
            $mediaPublicId = RouteParams::require($request, 'mediaPublicId');
            $input = ReplaceProductMediaUploadRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $result = $this->transaction->run(fn () => $this->replace->execute(
                $actor,
                new ReplaceProductMediaUploadCommand(
                    mediaPublicId: $mediaPublicId,
                    originalFileName: $input->originalFileName,
                    contentType: $input->contentType,
                    byteSize: $input->byteSize,
                    storageContainer: $this->settings->blobContainerProducts,
                    uploadExpiresInSeconds: $this->settings->blobUploadSasTtlSeconds,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::uploadSessionResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
