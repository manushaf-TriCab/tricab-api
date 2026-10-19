<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Mail;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Npr\Mail\NewNprOutboxEntry;
use TriCab\Core\Npr\Mail\NprMailOutbox;
use TriCab\Core\Npr\Mail\NprOutboxDelivery;
use TriCab\Core\Npr\Mail\NprOutboxEntry;
use TriCab\Core\Shared\Time\Clock;

/**
 * The outbox on Azure SQL: `[npr].[NotificationOutbox]` (one row per event, the
 * To/Cc snapshots and the template payload) and `[npr].[NotificationDeliveries]`
 * (one per address, plus a failed row for each required role with no address).
 * Schema: `create-npr-schema.sql`.
 *
 * `enqueue` is called by the request repository INSIDE its transition's
 * transaction on the same connection, so it joins that transaction; it never
 * opens its own.
 */
final class AzureSqlNprMailOutbox implements NprMailOutbox
{
    use AzureSqlTransaction;

    private const SELECT = 'SELECT o.[OutboxId], o.[Event], o.[ToSnapshotJson], o.[CcSnapshotJson], o.[PayloadSummary],
                o.[Status], o.[Attempts], o.[LastAttemptAt], o.[ErrorMessage], o.[CreatedAt],
                r.[PublicId], r.[RequestNumber]
            FROM [npr].[NotificationOutbox] o
            INNER JOIN [npr].[Requests] r ON r.[RequestId] = o.[RequestId]';

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly Clock $clock,
    ) {
    }

    public function enqueue(NewNprOutboxEntry $entry, int $activityId, int $requestId): int
    {
        $now = AzureSqlValueMapper::formatDateTime($this->clock->now());

        return $this->runInTransaction($this->connection, function () use ($entry, $activityId, $requestId, $now): int {
            $row = $this->connection->fetchAll(
                'INSERT INTO [npr].[NotificationOutbox]
                    ([ActivityId], [RequestId], [Event], [ToSnapshotJson], [CcSnapshotJson], [PayloadSummary], [Status], [Attempts], [CreatedAt])
                 OUTPUT INSERTED.[OutboxId]
                 VALUES (:activityId, :requestId, :event, :toJson, :ccJson, :payload, :status, 0, :createdAt)',
                [
                    'activityId' => $activityId,
                    'requestId' => $requestId,
                    'event' => $entry->event,
                    'toJson' => self::json($entry->to),
                    'ccJson' => self::json($entry->cc),
                    'payload' => self::json($entry->payload),
                    'status' => $entry->status,
                    'createdAt' => $now,
                ],
            )[0] ?? throw new RuntimeException('NPR outbox insert returned no row.');
            $outboxId = AzureSqlValueMapper::intValue($row['OutboxId'] ?? null);

            $deliveryStatus = $entry->status === NewNprOutboxEntry::STATUS_SUPPRESSED ? 'suppressed' : 'pending';
            foreach ([['to', $entry->to], ['cc', $entry->cc]] as [$type, $addresses]) {
                foreach ($addresses as $address) {
                    $this->delivery($outboxId, $address, $type, $deliveryStatus, null);
                }
            }
            foreach ($entry->missing as $missing) {
                $this->delivery(
                    $outboxId,
                    NprOutboxDelivery::MISSING_PREFIX . $missing['role'] . ')',
                    $missing['type'],
                    'failed',
                    'No address is configured for ' . $missing['role'] . '.',
                );
            }

            return $outboxId;
        });
    }

    public function pendingForRequest(string $requestPublicId): array
    {
        return $this->many(' WHERE o.[Status] = N\'pending\' AND r.[PublicId] = :id ORDER BY o.[OutboxId] ASC', ['id' => $requestPublicId]);
    }

    public function retryable(int $limit): array
    {
        $limit = max(1, min(500, $limit));

        return $this->many(
            ' WHERE o.[Status] = N\'pending\' OR (o.[Status] = N\'failed\' AND o.[Attempts] > 0 AND o.[Attempts] < ' . self::MAX_ATTEMPTS . ')
              ORDER BY o.[OutboxId] ASC OFFSET 0 ROWS FETCH NEXT ' . $limit . ' ROWS ONLY',
            [],
        );
    }

    public function find(int $id): ?NprOutboxEntry
    {
        return $this->many(' WHERE o.[OutboxId] = :id', ['id' => $id])[0] ?? null;
    }

    public function recent(int $limit, ?string $status): array
    {
        $limit = max(1, min(200, $limit));
        $where = $status === null ? '' : ' WHERE o.[Status] = :status';

        return $this->many(
            $where . ' ORDER BY o.[OutboxId] DESC OFFSET 0 ROWS FETCH NEXT ' . $limit . ' ROWS ONLY',
            $status === null ? [] : ['status' => $status],
        );
    }

    public function outstanding(): int
    {
        $row = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [Total] FROM [npr].[NotificationOutbox]
             WHERE [Status] = N\'pending\' OR ([Status] = N\'failed\' AND [Attempts] > 0 AND [Attempts] < ' . self::MAX_ATTEMPTS . ')',
        )[0] ?? [];

        return AzureSqlValueMapper::intValue($row['Total'] ?? 0);
    }

    public function recordAttempt(int $id, bool $sent, ?string $error, DateTimeImmutable $now): void
    {
        $stamp = AzureSqlValueMapper::formatDateTime($now);
        $this->runInTransaction($this->connection, function () use ($id, $sent, $error, $stamp): void {
            $this->connection->execute(
                'UPDATE [npr].[NotificationOutbox]
                 SET [Status] = :status, [Attempts] = [Attempts] + 1, [LastAttemptAt] = :at, [ErrorMessage] = :error
                 WHERE [OutboxId] = :id',
                ['status' => $sent ? 'sent' : 'failed', 'at' => $stamp, 'error' => $sent ? null : $error, 'id' => $id],
            );
            $this->connection->execute(
                'UPDATE [npr].[NotificationDeliveries]
                 SET [Status] = :status, [Attempts] = [Attempts] + 1, [SentAt] = :sentAt, [ErrorMessage] = :error
                 WHERE [OutboxId] = :id AND [RecipientEmail] NOT LIKE N\'(missing: %\'',
                ['status' => $sent ? 'sent' : 'failed', 'sentAt' => $sent ? $stamp : null, 'error' => $sent ? null : $error, 'id' => $id],
            );
        });
    }

    public function reopen(int $id): bool
    {
        return $this->runInTransaction($this->connection, function () use ($id): bool {
            $row = $this->connection->fetchAll(
                'SELECT [Status] FROM [npr].[NotificationOutbox] WITH (UPDLOCK, ROWLOCK) WHERE [OutboxId] = :id',
                ['id' => $id],
            )[0] ?? null;
            $status = $row === null ? '' : AzureSqlValueMapper::stringValue($row['Status'] ?? null);
            if (!in_array($status, ['failed', 'suppressed'], true)) {
                return false;
            }
            $this->connection->execute(
                'UPDATE [npr].[NotificationOutbox] SET [Status] = N\'pending\', [Attempts] = 0, [ErrorMessage] = NULL WHERE [OutboxId] = :id',
                ['id' => $id],
            );
            $this->connection->execute(
                'UPDATE [npr].[NotificationDeliveries] SET [Status] = N\'pending\', [Attempts] = 0, [SentAt] = NULL, [ErrorMessage] = NULL
                 WHERE [OutboxId] = :id AND [RecipientEmail] NOT LIKE N\'(missing: %\'',
                ['id' => $id],
            );

            return true;
        });
    }

    private function delivery(int $outboxId, string $email, string $type, string $status, ?string $error): void
    {
        $this->connection->execute(
            'INSERT INTO [npr].[NotificationDeliveries] ([OutboxId], [RecipientEmail], [RecipientType], [Status], [Attempts], [ErrorMessage])
             VALUES (:outboxId, :email, :type, :status, 0, :error)',
            ['outboxId' => $outboxId, 'email' => $email, 'type' => $type, 'status' => $status, 'error' => $error],
        );
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<NprOutboxEntry>
     */
    private function many(string $tail, array $params): array
    {
        $rows = $this->connection->fetchAll(self::SELECT . $tail, $params);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => AzureSqlValueMapper::intValue($row['OutboxId'] ?? null), $rows);
        $deliveries = [];
        foreach ($this->connection->fetchAll(
            'SELECT [DeliveryId], [OutboxId], [RecipientEmail], [RecipientType], [Status], [Attempts], [SentAt], [ErrorMessage]
             FROM [npr].[NotificationDeliveries] WHERE [OutboxId] IN (' . implode(',', $ids) . ') ORDER BY [DeliveryId] ASC',
        ) as $d) {
            $sentAt = AzureSqlValueMapper::nullableString($d['SentAt'] ?? null);
            $deliveries[AzureSqlValueMapper::intValue($d['OutboxId'] ?? null)][] = new NprOutboxDelivery(
                AzureSqlValueMapper::intValue($d['DeliveryId'] ?? null),
                AzureSqlValueMapper::stringValue($d['RecipientEmail'] ?? null),
                AzureSqlValueMapper::stringValue($d['RecipientType'] ?? null),
                AzureSqlValueMapper::stringValue($d['Status'] ?? null),
                AzureSqlValueMapper::intValue($d['Attempts'] ?? null),
                $sentAt === null ? null : self::utc($sentAt),
                AzureSqlValueMapper::nullableString($d['ErrorMessage'] ?? null),
            );
        }

        return array_map(static function (array $row) use ($deliveries): NprOutboxEntry {
            $id = AzureSqlValueMapper::intValue($row['OutboxId'] ?? null);
            $last = AzureSqlValueMapper::nullableString($row['LastAttemptAt'] ?? null);

            return new NprOutboxEntry(
                $id,
                strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
                AzureSqlValueMapper::stringValue($row['RequestNumber'] ?? null),
                AzureSqlValueMapper::stringValue($row['Event'] ?? null),
                self::strings(AzureSqlValueMapper::stringValue($row['ToSnapshotJson'] ?? null)),
                self::strings(AzureSqlValueMapper::stringValue($row['CcSnapshotJson'] ?? null)),
                self::object(AzureSqlValueMapper::stringValue($row['PayloadSummary'] ?? null)),
                AzureSqlValueMapper::stringValue($row['Status'] ?? null),
                AzureSqlValueMapper::intValue($row['Attempts'] ?? null),
                $last === null ? null : self::utc($last),
                AzureSqlValueMapper::nullableString($row['ErrorMessage'] ?? null),
                self::utc(AzureSqlValueMapper::stringValue($row['CreatedAt'] ?? null)),
                $deliveries[$id] ?? [],
            );
        }, $rows);
    }

    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    private static function strings(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @return array<string, mixed> */
    private static function object(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_filter($decoded, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }
}
