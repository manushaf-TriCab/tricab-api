<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Mail;

use DateTimeImmutable;
use JsonException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;

/**
 * `finance.mail_deliveries` (`create-finance-schema.sql`). The pending row is
 * written with one statement that refuses to insert where the application's
 * idempotency key is already taken, and the filtered unique index
 * `UX_finance_mail_deliveries_idempotency` settles two writers that race past it.
 */
final class AzureSqlFinanceMailLedger implements FinanceMailLedger
{
    private const COLUMNS = '[id], [application_key], [actor_user_id], [mailbox_email], [to_addresses_json], [cc_addresses_json],
        [subject], [body_sha256], [attachment_names_json], [idempotency_key], [status], [error_message], [created_at], [sent_at]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function findByIdempotencyKey(string $application, string $key): ?FinanceMailDelivery
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[mail_deliveries] WHERE [application_key] = :app AND [idempotency_key] = :key',
            ['app' => $application, 'key' => $key],
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function findById(string $id): ?FinanceMailDelivery
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[mail_deliveries] WHERE [id] = :id',
            ['id' => $id],
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function recordPending(FinanceMailDelivery $delivery): bool
    {
        $params = [
            'id' => $delivery->id,
            'app' => $delivery->application,
            'actor' => $delivery->actorUserId,
            'mailbox' => $delivery->mailbox,
            'to_json' => json_encode($delivery->to, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'cc_json' => json_encode($delivery->cc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'subject' => $delivery->subject,
            'sha' => $delivery->bodySha256,
            'names_json' => json_encode($delivery->attachmentNames, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'key' => $delivery->idempotencyKey,
            'created' => AzureSqlValueMapper::formatDateTime($delivery->createdAt),
        ];

        try {
            $this->connection->execute(
                'INSERT INTO [finance].[mail_deliveries]
                    ([id], [application_key], [actor_user_id], [mailbox_email], [to_addresses_json], [cc_addresses_json],
                     [subject], [body_sha256], [attachment_names_json], [idempotency_key], [status], [created_at])
                 SELECT :id, :app, :actor, :mailbox, :to_json, :cc_json, :subject, :sha, :names_json, :key, \'pending\', :created
                 WHERE :key2 IS NULL OR NOT EXISTS (
                     SELECT 1 FROM [finance].[mail_deliveries] WHERE [application_key] = :app2 AND [idempotency_key] = :key3)',
                $params + ['key2' => $delivery->idempotencyKey, 'app2' => $delivery->application, 'key3' => $delivery->idempotencyKey],
            );
        } catch (DatabaseUnavailable $failure) {
            // Two writers raced past the check and the unique index refused the second.
            if ($delivery->idempotencyKey !== null && $this->findByIdempotencyKey($delivery->application, $delivery->idempotencyKey) !== null) {
                return false;
            }
            throw $failure;
        }

        return $this->connection->fetchAll(
            'SELECT [id] FROM [finance].[mail_deliveries] WHERE [id] = :id',
            ['id' => $delivery->id],
        ) !== [];
    }

    public function markSent(string $id, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE [finance].[mail_deliveries] SET [status] = \'sent\', [sent_at] = :at, [error_message] = NULL WHERE [id] = :id',
            ['at' => AzureSqlValueMapper::formatDateTime($at), 'id' => $id],
        );
    }

    public function markFailed(string $id, string $error): void
    {
        $this->connection->execute(
            'UPDATE [finance].[mail_deliveries] SET [status] = \'failed\', [error_message] = :error WHERE [id] = :id',
            ['error' => mb_substr($error, 0, 500), 'id' => $id],
        );
    }

    public function markSuppressed(string $id, string $reason): void
    {
        $this->connection->execute(
            'UPDATE [finance].[mail_deliveries] SET [status] = \'suppressed\', [error_message] = :reason WHERE [id] = :id',
            ['reason' => mb_substr($reason, 0, 500), 'id' => $id],
        );
    }

    public function reopenSuppressed(FinanceMailDelivery $delivery): bool
    {
        // One statement, and OUTPUT says whether it took the row: only a row still
        // `suppressed` is reopened, so two callers cannot both win it.
        return $this->connection->fetchAll(
            'UPDATE [finance].[mail_deliveries]
                SET [status] = \'pending\', [actor_user_id] = :actor, [mailbox_email] = :mailbox,
                    [to_addresses_json] = :to_json, [cc_addresses_json] = :cc_json, [subject] = :subject,
                    [body_sha256] = :sha, [attachment_names_json] = :names_json, [error_message] = NULL,
                    [sent_at] = NULL, [created_at] = :created
                OUTPUT inserted.[id]
              WHERE [id] = :id AND [status] = \'suppressed\'',
            [
                'actor' => $delivery->actorUserId,
                'mailbox' => $delivery->mailbox,
                'to_json' => json_encode($delivery->to, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'cc_json' => json_encode($delivery->cc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'subject' => $delivery->subject,
                'sha' => $delivery->bodySha256,
                'names_json' => json_encode($delivery->attachmentNames, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created' => AzureSqlValueMapper::formatDateTime($delivery->createdAt),
                'id' => $delivery->id,
            ],
        ) !== [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): FinanceMailDelivery
    {
        return new FinanceMailDelivery(
            AzureSqlValueMapper::stringValue($row['id']),
            AzureSqlValueMapper::stringValue($row['application_key']),
            AzureSqlValueMapper::nullableInt($row['actor_user_id']),
            AzureSqlValueMapper::stringValue($row['mailbox_email']),
            self::strings($row['to_addresses_json']),
            self::strings($row['cc_addresses_json']),
            AzureSqlValueMapper::stringValue($row['subject']),
            AzureSqlValueMapper::stringValue($row['body_sha256']),
            self::strings($row['attachment_names_json']),
            AzureSqlValueMapper::nullableString($row['idempotency_key']),
            AzureSqlValueMapper::stringValue($row['status']),
            AzureSqlValueMapper::nullableString($row['error_message']),
            AzureSqlValueMapper::requireDateTime($row['created_at'], 'created_at'),
            AzureSqlValueMapper::nullableDateTime($row['sent_at']),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $json): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode(AzureSqlValueMapper::stringValue($json), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
