<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\UniqueConstraintTranslator;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteIdempotencyConflictException;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;

/**
 * Azure SQL implementation of quote write idempotency.
 * Requires applied migration: create-quote-write-idempotency-schema.sql
 */
final class AzureSqlQuoteWriteIdempotencyStore implements QuoteWriteIdempotencyStore
{
    use UniqueConstraintTranslator;

    private const TABLE = '[quote].[QuoteWriteIdempotency]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?QuoteWriteIdempotencyRecord {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) * FROM ' . self::TABLE
            . ' WHERE [ActorKey] = :actor AND [Operation] = :op AND [IdempotencyKey] = :ikey',
            [
                ':actor' => $actorKey,
                ':op' => $operation,
                ':ikey' => $idempotencyKey,
            ],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function tryAcquire(QuoteWriteIdempotencyRecord $record): QuoteWriteIdempotencyRecord
    {
        try {
            return $this->translatingUniqueConstraintViolation(
                function () use ($record): QuoteWriteIdempotencyRecord {
                    $this->connection->execute(
                        'INSERT INTO ' . self::TABLE . ' (
                            [ActorKey], [Operation], [IdempotencyKey], [RequestHash], [State],
                            [PlatformOperationId], [QuoteId], [CartPublicId], [ReconciliationFingerprint],
                            [CreatedAtUtc], [UpdatedAtUtc]
                        ) VALUES (
                            :actor, :op, :ikey, :hash, :state,
                            :platformOp, :quoteId, :cartId, :fingerprint,
                            :created, :updated
                        )',
                        [
                            ':actor' => $record->actorKey,
                            ':op' => $record->operation,
                            ':ikey' => $record->idempotencyKey,
                            ':hash' => $record->requestHash,
                            ':state' => $record->state,
                            ':platformOp' => $record->platformOperationId,
                            ':quoteId' => $record->quoteId,
                            ':cartId' => $record->cartPublicId,
                            ':fingerprint' => $record->reconciliationFingerprint,
                            ':created' => AzureSqlValueMapper::formatDateTime($record->createdAtUtc),
                            ':updated' => AzureSqlValueMapper::formatDateTime($record->updatedAtUtc),
                        ],
                    );

                    return $record;
                },
                static fn () => QuoteWriteIdempotencyConflictException::requestMismatch(),
            );
        } catch (QuoteWriteIdempotencyConflictException) {
            $existing = $this->find($record->actorKey, $record->operation, $record->idempotencyKey);
            if ($existing === null) {
                throw QuoteWriteIdempotencyConflictException::requestMismatch();
            }
            if (!hash_equals($existing->requestHash, $record->requestHash)) {
                throw QuoteWriteIdempotencyConflictException::requestMismatch();
            }

            return $existing;
        }
    }

    public function markCompleted(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $quoteId,
    ): void {
        $this->connection->execute(
            'UPDATE ' . self::TABLE
            . ' SET [State] = :state, [QuoteId] = :quoteId, [UpdatedAtUtc] = SYSUTCDATETIME()'
            . ' WHERE [ActorKey] = :actor AND [Operation] = :op AND [IdempotencyKey] = :ikey',
            [
                ':state' => QuoteWriteIdempotencyState::COMPLETED,
                ':quoteId' => $quoteId,
                ':actor' => $actorKey,
                ':op' => $operation,
                ':ikey' => $idempotencyKey,
            ],
        );
    }

    public function markFailed(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $failureReason,
    ): void {
        $this->connection->execute(
            'UPDATE ' . self::TABLE
            . ' SET [State] = :state, [FailureReason] = :reason, [UpdatedAtUtc] = SYSUTCDATETIME()'
            . ' WHERE [ActorKey] = :actor AND [Operation] = :op AND [IdempotencyKey] = :ikey',
            [
                ':state' => QuoteWriteIdempotencyState::FAILED,
                ':reason' => substr($failureReason, 0, 256),
                ':actor' => $actorKey,
                ':op' => $operation,
                ':ikey' => $idempotencyKey,
            ],
        );
    }

    public function findByPlatformOperationId(string $platformOperationId): ?QuoteWriteIdempotencyRecord
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) * FROM ' . self::TABLE . ' WHERE [PlatformOperationId] = :op',
            [':op' => $platformOperationId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): QuoteWriteIdempotencyRecord
    {
        $created = AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null) ?? 'now';
        $updated = AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null) ?? $created;

        return new QuoteWriteIdempotencyRecord(
            actorKey: AzureSqlValueMapper::stringValue($row['ActorKey'] ?? ''),
            operation: AzureSqlValueMapper::stringValue($row['Operation'] ?? ''),
            idempotencyKey: AzureSqlValueMapper::stringValue($row['IdempotencyKey'] ?? ''),
            requestHash: AzureSqlValueMapper::stringValue($row['RequestHash'] ?? ''),
            state: AzureSqlValueMapper::stringValue($row['State'] ?? QuoteWriteIdempotencyState::IN_PROGRESS),
            platformOperationId: AzureSqlValueMapper::stringValue($row['PlatformOperationId'] ?? ''),
            quoteId: AzureSqlValueMapper::nullableString($row['QuoteId'] ?? null),
            cartPublicId: AzureSqlValueMapper::nullableString($row['CartPublicId'] ?? null),
            reconciliationFingerprint: AzureSqlValueMapper::nullableString($row['ReconciliationFingerprint'] ?? null),
            createdAtUtc: new DateTimeImmutable($created, new DateTimeZone('UTC')),
            updatedAtUtc: new DateTimeImmutable($updated, new DateTimeZone('UTC')),
            failureReason: AzureSqlValueMapper::nullableString($row['FailureReason'] ?? null),
        );
    }
}
