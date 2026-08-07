<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\AddProductMediaRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\Command\AddProductMediaCommand;
use TriCab\Core\Product\Application\Service\AddProductMediaService;

final class AddProductMediaAction
{
    public function __construct(
        private readonly AddProductMediaService $addMedia,
        private readonly ProductDevMediaUrlResolver $devUrls,
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
            $input = AddProductMediaRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $created = $this->transaction->run(fn () => $this->addMedia->execute(
                $actor,
                new AddProductMediaCommand(
                    productPublicId: $productPublicId,
                    mediaCategoryCode: $input->mediaCategoryCode,
                    mediaTypeCode: $input->mediaTypeCode,
                    title: $input->title,
                    storagePath: $input->storagePath,
                    storageContainer: $input->storageContainer,
                    contentType: $input->contentType,
                    fileExtension: $input->fileExtension,
                    byteSize: $input->byteSize,
                    altText: $input->altText,
                    caption: $input->caption,
                    displayOrder: $input->displayOrder,
                    isPrimary: $input->isPrimary,
                    publicUrl: $input->publicUrl,
                ),
            ));

            return $this->responder->success(
                ProductManagementDtoSerializer::mediaSummary($created, true, $this->devUrls),
                201,
            );
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
