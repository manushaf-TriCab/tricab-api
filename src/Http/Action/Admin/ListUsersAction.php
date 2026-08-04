<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\UserListQuery;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\UserManagement\ListUsersService;

final class ListUsersAction
{
    public function __construct(
        private readonly ListUsersService $listUsers,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $criteria = UserListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->listUsers->execute($criteria);

            return $this->responder->success([
                'items' => array_map(UserManagementDtoSerializer::userSummary(...), $result->items),
                'pagination' => UserManagementDtoSerializer::pagination($result->pagination),
                'counts' => UserManagementDtoSerializer::counts($result->counts),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
