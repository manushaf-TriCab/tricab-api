<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\UserManagement\ListRolesService;

final class ListRolesAction
{
    public function __construct(
        private readonly ListRolesService $listRoles,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $items = $this->listRoles->execute(
                search: QueryParams::stringOrNull($query, 'search'),
                isActive: QueryParams::boolOrNull($query, 'isActive'),
            );

            return $this->responder->success([
                'items' => array_map(UserManagementDtoSerializer::roleSummary(...), $items),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
