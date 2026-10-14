<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\UpdatePageContentRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\UpdateProductPageContentCommand;
use TriCab\Core\Product\Application\Service\UpdateProductPageContentService;

final class UpdateProductPageContentAction
{
    public function __construct(
        private readonly UpdateProductPageContentService $updateContent,
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
            $input = UpdatePageContentRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $this->transaction->run(fn () => $this->updateContent->execute(
                $actor,
                new UpdateProductPageContentCommand(
                    productPublicId: $productPublicId,
                    content: $input->content,
                    expectedVersion: $input->expectedVersion,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::acknowledged());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
