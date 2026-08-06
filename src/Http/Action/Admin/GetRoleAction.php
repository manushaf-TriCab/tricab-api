<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\UserManagement\GetRoleDetailsService;

final class GetRoleAction
{
    public function __construct(
        private readonly GetRoleDetailsService $getRoleDetails,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $roleCode = RouteParams::require($request, 'roleCode');
            $details = $this->getRoleDetails->execute($roleCode);

            return $this->responder->success(UserManagementDtoSerializer::roleDetails($details));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
