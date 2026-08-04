<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\CreateRoleRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateRoleService;

final class CreateRoleAction
{
    public function __construct(
        private readonly CreateRoleService $createRole,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = CreateRoleRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var RoleDetails $created */
            $created = $this->transaction->run(fn (): RoleDetails => $this->createRole->execute(
                actor: $actor,
                roleCode: $input->roleCode,
                roleName: $input->roleName,
                description: $input->description,
                isActive: $input->isActive,
            ));

            return $this->responder->success(UserManagementDtoSerializer::roleDetails($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
