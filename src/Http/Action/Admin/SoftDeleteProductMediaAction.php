<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Http\ProductSubResourceResolver;
use TriCab\Core\Product\Application\Command\SoftDeleteProductMediaCommand;
use TriCab\Core\Product\Application\Service\SoftDeleteProductMediaService;

final class SoftDeleteProductMediaAction
{
    public function __construct(
        private readonly SoftDeleteProductMediaService $softDeleteMedia,
        private readonly ProductSubResourceResolver $resolver,
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
            $productPublicId = $this->resolver->requireProductPublicIdForMedia($mediaPublicId);
            $actor = AdminActorFactory::fromRequest($request);

            $this->transaction->run(fn () => $this->softDeleteMedia->execute(
                $actor,
                new SoftDeleteProductMediaCommand($productPublicId, $mediaPublicId),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::acknowledged());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
