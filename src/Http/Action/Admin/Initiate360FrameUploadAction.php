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
use TriCab\Api\Http\Request\Admin\Product\Initiate360FrameUploadRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\Initiate360FrameUploadCommand;
use TriCab\Core\Product\Application\Service\Initiate360FrameUploadService;

final class Initiate360FrameUploadAction
{
    public function __construct(
        private readonly Initiate360FrameUploadService $initiate,
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
            $setPublicId = RouteParams::require($request, 'setPublicId');
            $input = Initiate360FrameUploadRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $result = $this->transaction->run(fn () => $this->initiate->execute(
                $actor,
                new Initiate360FrameUploadCommand(
                    setPublicId: $setPublicId,
                    frameNumber: $input->frameNumber,
                    originalFileName: $input->originalFileName,
                    contentType: $input->contentType,
                    byteSize: $input->byteSize,
                    storageContainer: $this->settings->blobContainerProducts,
                    uploadExpiresInSeconds: $this->settings->blobUploadSasTtlSeconds,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::uploadSessionResult($result), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
