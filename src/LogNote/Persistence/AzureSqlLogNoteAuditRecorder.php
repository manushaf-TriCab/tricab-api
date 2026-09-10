<?php

declare(strict_types=1);

namespace TriCab\Api\LogNote\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LogNote\Contract\LogNoteAuditRecorder;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;

final class AzureSqlLogNoteAuditRecorder implements LogNoteAuditRecorder
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function recordCreated(
        UserId $actorUserId,
        LogNotePublicId $notePublicId,
        CustomerPublicId $customerPublicId,
    ): void {
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [customer].[LogNoteAuditEvents] (
    [ActorUserId],
    [ActionCode],
    [LogNotePublicId],
    [CustomerPublicId],
    [CreatedAtUtc]
)
VALUES (
    :actorUserId,
    N'CUSTOMER_NOTE_CREATED',
    :notePublicId,
    :customerPublicId,
    SYSUTCDATETIME()
)
SQL,
            [
                ':actorUserId' => $actorUserId->toInt(),
                ':notePublicId' => $notePublicId->toString(),
                ':customerPublicId' => $customerPublicId->toString(),
            ],
        );
    }
}
