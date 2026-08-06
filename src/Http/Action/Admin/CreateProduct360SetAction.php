<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\CreateProduct360SetRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\CreateProduct360SetCommand;
use TriCab\Core\Product\Application\Service\CreateProduct360SetService;

final class CreateProduct360SetAction
{
    public function __construct(
        private readonly CreateProduct360SetService $create,
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
            $input = CreateProduct360SetRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $created = $this->transaction->run(fn () => $this->create->execute(
                $actor,
                new CreateProduct360SetCommand(
                    productPublicId: $productPublicId,
                    title: $input->title,
                    expectedFrameCount: $input->expectedFrameCount,
                    displayOrder: $input->displayOrder,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::product360SetSummary($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
