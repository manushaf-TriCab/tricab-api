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
use TriCab\Core\Product\Application\Command\ClearProductPresentationMediaRoleCommand;
use TriCab\Core\Product\Application\Service\ClearProductPresentationMediaRoleService;

final class ClearProductPresentationMediaRoleAction
{
    public function __construct(
        private readonly ClearProductPresentationMediaRoleService $clearRole,
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
            $roleCode = RouteParams::require($request, 'roleCode');
            $actor = AdminActorFactory::fromRequest($request);

            $this->transaction->run(fn () => $this->clearRole->execute(
                $actor,
                new ClearProductPresentationMediaRoleCommand($productPublicId, $roleCode),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::acknowledged());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
