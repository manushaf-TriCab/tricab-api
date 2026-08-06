<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\UserManagement\ListPermissionsService;

final class ListPermissionsAction
{
    public function __construct(
        private readonly ListPermissionsService $listPermissions,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $items = $this->listPermissions->execute(
                search: QueryParams::stringOrNull($query, 'search'),
                isActive: QueryParams::boolOrNull($query, 'isActive'),
                moduleCode: QueryParams::stringOrNull($query, 'moduleCode'),
            );

            return $this->responder->success([
                'items' => array_map(UserManagementDtoSerializer::permissionSummary(...), $items),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
