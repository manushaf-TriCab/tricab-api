<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\UserManagement\GetUserManagementAuditHistoryService;

final class ListAuditAction
{
    public function __construct(
        private readonly GetUserManagementAuditHistoryService $getAuditHistory,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $result = $this->getAuditHistory->execute(
                page: QueryParams::int($query, 'page', 1),
                pageSize: QueryParams::int($query, 'pageSize', 25),
                targetUserPublicId: QueryParams::stringOrNull($query, 'targetUserPublicId'),
                targetRoleCode: QueryParams::stringOrNull($query, 'targetRoleCode'),
                eventType: QueryParams::stringOrNull($query, 'eventType'),
            );

            return $this->responder->success([
                'items' => array_map(UserManagementDtoSerializer::auditEntry(...), $result->items),
                'pagination' => UserManagementDtoSerializer::pagination($result->pagination),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
