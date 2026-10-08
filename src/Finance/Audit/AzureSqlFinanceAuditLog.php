<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Audit;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;

/** `finance.audit_events` (`create-finance-schema.sql`). Append-only. */
final class AzureSqlFinanceAuditLog implements FinanceAuditLog
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function record(FinanceAuditEvent $event): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[audit_events]
                ([id], [actor_user_id], [application_key], [action], [entity_type], [entity_id], [details_json], [created_at])
             VALUES (:id, :actor, :app, :action, :type, :entity, :details, :at)',
            [
                'id' => bin2hex(random_bytes(16)),
                'actor' => $event->actorUserId,
                'app' => $event->application,
                'action' => $event->action,
                'type' => $event->entityType,
                'entity' => $event->entityId,
                'details' => json_encode($event->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                'at' => AzureSqlValueMapper::formatDateTime($event->at),
            ],
        );
    }
}
