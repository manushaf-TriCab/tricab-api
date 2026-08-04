<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\CreatePermissionRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Application\Service\UserManagement\CreatePermissionService;

final class CreatePermissionAction
{
    public function __construct(
        private readonly CreatePermissionService $createPermission,
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
            $input = CreatePermissionRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var PermissionSummary $created */
            $created = $this->transaction->run(fn (): PermissionSummary => $this->createPermission->execute(
                actor: $actor,
                permissionCode: $input->permissionCode,
                permissionName: $input->permissionName,
                moduleCode: $input->moduleCode,
                description: $input->description,
                isActive: $input->isActive,
            ));

            return $this->responder->success(UserManagementDtoSerializer::permissionSummary($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
