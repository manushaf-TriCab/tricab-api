<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Service\UserManagement\RemoveRoleFromUserService;

final class RemoveUserRoleAction
{
    public function __construct(
        private readonly RemoveRoleFromUserService $removeRoleFromUser,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'publicId');
            $roleCode = RouteParams::require($request, 'roleCode');
            $actor = AdminActorFactory::fromRequest($request);

            /** @var UserDetails $updated */
            $updated = $this->transaction->run(fn (): UserDetails => $this->removeRoleFromUser->execute(
                actor: $actor,
                publicId: $publicId,
                roleCode: $roleCode,
            ));

            return $this->responder->success(UserManagementDtoSerializer::userDetails($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
