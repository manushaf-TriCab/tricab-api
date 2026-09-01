<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\UpdateThumbnailPresentationRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\UpdateProductThumbnailPresentationCommand;
use TriCab\Core\Product\Application\Service\UpdateProductThumbnailPresentationService;

final class UpdateProductThumbnailPresentationAction
{
    public function __construct(
        private readonly UpdateProductThumbnailPresentationService $updatePresentation,
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
            $input = UpdateThumbnailPresentationRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $color = $input->clearThumbnailBackgroundColor
                ? null
                : $input->thumbnailBackgroundColor;

            $this->transaction->run(fn () => $this->updatePresentation->execute(
                $actor,
                new UpdateProductThumbnailPresentationCommand(
                    productPublicId: $productPublicId,
                    thumbnailBackgroundColor: $color,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::acknowledged());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
