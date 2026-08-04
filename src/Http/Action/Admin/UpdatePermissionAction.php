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
use TriCab\Api\Http\Request\Admin\UpdatePermissionRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Application\Service\UserManagement\UpdatePermissionService;

final class UpdatePermissionAction
{
    public function __construct(
        private readonly UpdatePermissionService $updatePermission,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $permissionCode = RouteParams::require($request, 'permissionCode');
            $body = JsonRequestBody::requireObject($request);
            $input = UpdatePermissionRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var PermissionSummary $updated */
            $updated = $this->transaction->run(fn (): PermissionSummary => $this->updatePermission->execute(
                actor: $actor,
                permissionCode: $permissionCode,
                permissionName: $input->permissionName,
                description: $input->description,
                isActive: $input->isActive,
            ));

            return $this->responder->success(UserManagementDtoSerializer::permissionSummary($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
