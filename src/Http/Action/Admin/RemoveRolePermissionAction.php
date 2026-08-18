<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Service\UserManagement\RemovePermissionFromRoleService;

final class RemoveRolePermissionAction
{
    public function __construct(
        private readonly RemovePermissionFromRoleService $removePermissionFromRole,
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
            $permissionCode = self::resolvePermissionCode($request);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var RoleDetails $updated */
            $updated = $this->transaction->run(fn (): RoleDetails => $this->removePermissionFromRole->execute(
                actor: $actor,
                roleCode: $roleCode,
                permissionCode: $permissionCode,
            ));

            return $this->responder->success(UserManagementDtoSerializer::roleDetails($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    /**
     * Prefer ?permissionCode= so the last path segment is `permissions`.
     * PHP's built-in server treats a dotted last segment (PRODUCT.READ) as a
     * missing static file, so CORS preflight never reaches Slim.
     */
    private static function resolvePermissionCode(ServerRequestInterface $request): string
    {
        $fromQuery = QueryParams::stringOrNull(
            QueryParams::normalize($request->getQueryParams()),
            'permissionCode',
        );
        $permissionCode = $fromQuery ?? RouteParams::optional($request, 'permissionCode');
        if ($permissionCode === null) {
            throw HttpRequestValidationFailed::invalid('permissionCode is required.');
        }

        return $permissionCode;
    }
}
