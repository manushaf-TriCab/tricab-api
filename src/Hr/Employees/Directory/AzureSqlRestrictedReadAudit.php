<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/** Writes `[audit].[HrRestrictedReadEvents]`. Identifiers only, never a restricted value. */
final class AzureSqlRestrictedReadAudit implements RestrictedReadAudit
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function record(ActorContext $actor, string $employeeCode, string $endpoint): void
    {
        $this->connection->execute(
            <<<'SQL'
            INSERT INTO [audit].[HrRestrictedReadEvents] (
                [ActorUserId], [EmployeeCode], [Endpoint], [PermissionCode],
                [RequestCorrelationId], [IpAddress], [UserAgent]
            ) VALUES (
                :actorUserId, :employeeCode, :endpoint, :permissionCode,
                :correlationId, :ipAddress, :userAgent
            )
            SQL,
            [
                ':actorUserId' => $actor->userId,
                ':employeeCode' => mb_substr($employeeCode, 0, 20),
                ':endpoint' => mb_substr($endpoint, 0, 200),
                ':permissionCode' => HrPermissions::EMPLOYEES_RESTRICTED_READ,
                ':correlationId' => $actor->correlationId === null ? null : mb_substr($actor->correlationId, 0, 100),
                ':ipAddress' => $actor->ipAddress === null ? null : mb_substr($actor->ipAddress, 0, 64),
                ':userAgent' => $actor->userAgent === null ? null : mb_substr($actor->userAgent, 0, 500),
            ],
        );
    }
}
