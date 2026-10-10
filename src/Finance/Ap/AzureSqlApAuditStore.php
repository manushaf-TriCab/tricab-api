<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;

/**
 * Tri-AP's audit trail in `finance.invoice_audit_events` (`create-finance-invoice-schema.sql`). Append-only; a write joins
 * the transaction the invoice store opened on the same connection.
 */
final class AzureSqlApAuditStore extends AzureSqlApSupport implements ApAuditStore
{
    public function record(ApAuditEvent $event): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[invoice_audit_events] ([id], [actor_user_id], [actor_name], [action], [entity_type], [entity_id], [details], [created_date])
             VALUES (:id, :actor, :name, :action, :type, :entity, :details, :at)',
            [
                'id' => bin2hex(random_bytes(16)),
                'actor' => $event->actorUserId,
                'name' => mb_substr($event->actorName, 0, 200),
                'action' => mb_substr($event->action, 0, 60),
                'type' => mb_substr($event->entityType, 0, 40),
                'entity' => mb_substr($event->entityId, 0, 100),
                'details' => json_encode($event->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                'at' => self::at($event->at),
            ],
        );
    }

    public function forEntity(string $entityType, string $entityId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [actor_user_id], [actor_name], [action], [entity_type], [entity_id], [details], [created_date]
               FROM [finance].[invoice_audit_events] WHERE [entity_type] = :type AND [entity_id] = :entity
              ORDER BY [created_date], [id]',
            ['type' => $entityType, 'entity' => $entityId],
        );
        $events = [];
        foreach ($rows as $row) {
            $details = json_decode(self::text($row['details']), true);
            $events[] = new ApAuditEvent(
                self::text($row['action']),
                self::text($row['entity_type']),
                self::text($row['entity_id']),
                is_array($details) ? self::keyed($details) : [],
                self::when($row['created_date'], 'created_date'),
                $row['actor_user_id'] === null ? null : self::int($row['actor_user_id']),
                self::text($row['actor_name']),
            );
        }

        return $events;
    }

    /**
     * @param array<array-key, mixed> $details
     * @return array<string, mixed>
     */
    private static function keyed(array $details): array
    {
        $out = [];
        foreach ($details as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
