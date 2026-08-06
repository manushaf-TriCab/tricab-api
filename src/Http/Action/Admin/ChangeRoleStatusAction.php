<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\ChangeRoleStatusRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Service\UserManagement\ChangeRoleStatusService;

final class ChangeRoleStatusAction
{
    public function __construct(
        private readonly ChangeRoleStatusService $changeRoleStatus,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $roleCode = RouteParams::require($request, 'roleCode');
            $body = JsonRequestBody::requireObject($request);
            $input = ChangeRoleStatusRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var RoleDetails $updated */
            $updated = $this->transaction->run(fn (): RoleDetails => $this->changeRoleStatus->execute(
                actor: $actor,
                roleCode: $roleCode,
                isActive: $input->isActive,
            ));

            return $this->responder->success(UserManagementDtoSerializer::roleDetails($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
