<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\UniqueConstraintTranslator;
use TriCab\Core\Onboarding\Application\DTO\OnboardingWriteIdempotencyRecord;
use TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;

/**
 * The replay ledger, against `[onboarding].[OnboardingWriteIdempotency]`.
 *
 * Follows {@see \TriCab\Api\QuoteOrder\Persistence\AzureSqlQuoteWriteIdempotencyStore}
 * closely enough that the two can be read side by side, which is the point: the
 * schema comment says two different shapes of the same idea in one database is
 * how one of them ends up wrong.
 *
 * ## The acquisition race, and why losing it is not an error
 *
 * Two requests with the same key arrive together. One INSERT wins,
 * `UX_onboarding_OnboardingWriteIdempotency_Actor_Op_Key` refuses the other, and
 * the loser then reads the row the winner wrote. If the hashes match it is a
 * retry and the held record is returned; if they differ it is a different
 * request wearing the same key, and that is a conflict. Catching the constraint
 * and re-reading is the only version of this that is correct without a lock.
 *
 * ## A COMPLETED row always carries what it produced
 *
 * Phase 2.5 proved no CHECK constraint requires it and recorded that none should
 * — the constraint would have to permit the window between the write and the
 * ledger update anyway. So {@see markCompleted()} always passes one, and
 * `OnboardingConcurrencyAndIdempotencyTest` is where that obligation is held.
 */
final class AzureSqlOnboardingWriteIdempotencyStore implements OnboardingWriteIdempotencyStore
{
    use UniqueConstraintTranslator;

    private const TABLE = '[onboarding].[OnboardingWriteIdempotency]';

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?OnboardingWriteIdempotencyRecord {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) [ActorKey], [Operation], [IdempotencyKey], [RequestHash], [State],'
            . ' CONVERT(NVARCHAR(36), [PlatformOperationId]) AS [PlatformOperationId],'
            . ' CONVERT(NVARCHAR(36), [ResultPublicId]) AS [ResultPublicId], [FailureReason],'
            . ' CONVERT(VARCHAR(33), [CreatedAtUtc], 127) AS [CreatedAtUtc],'
            . ' CONVERT(VARCHAR(33), [UpdatedAtUtc], 127) AS [UpdatedAtUtc]'
            . ' FROM ' . self::TABLE
            . ' WHERE [ActorKey] = :actor AND [Operation] = :op AND [IdempotencyKey] = :ikey',
            [':actor' => $actorKey, ':op' => $operation, ':ikey' => $idempotencyKey],
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function tryAcquire(OnboardingWriteIdempotencyRecord $record): OnboardingWriteIdempotencyRecord
    {
        try {
            return $this->translatingUniqueConstraintViolation(
                function () use ($record): OnboardingWriteIdempotencyRecord {
                    $this->connection->execute(
                        'INSERT INTO ' . self::TABLE . ' ('
                        . '[ActorKey], [Operation], [IdempotencyKey], [RequestHash], [State],'
                        . ' [PlatformOperationId], [ResultPublicId]'
                        . ') VALUES ('
                        . ':actor, :op, :ikey, :hash, :state,'
                        . ' CONVERT(UNIQUEIDENTIFIER, :platformOp), :resultPublicId'
                        . ')',
                        [
                            ':actor' => $record->actorKey,
                            ':op' => $record->operation,
                            ':ikey' => $record->idempotencyKey,
                            ':hash' => $record->requestHash,
                            ':state' => $record->state,
                            ':platformOp' => $record->platformOperationId,
                            ':resultPublicId' => $record->resultPublicId,
                        ],
                    );

                    return $record;
                },
                static fn (): Throwable => OnboardingWriteConflict::idempotencyKeyReused(),
            );
        } catch (OnboardingWriteConflict) {
            $existing = $this->find($record->actorKey, $record->operation, $record->idempotencyKey);

            // The insert failed on a unique constraint and the row is not there.
            // That is not this key colliding with itself — it is the
            // PlatformOperationId index, or something else entirely — and
            // reporting a key reuse would send the client to the wrong fix.
            if ($existing === null) {
                throw OnboardingWriteConflict::idempotencyKeyReused();
            }

            if (!hash_equals($existing->requestHash, $record->requestHash)) {
                throw OnboardingWriteConflict::idempotencyKeyReused();
            }

            return $existing;
        }
    }

    public function markCompleted(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $resultPublicId,
    ): void {
        $this->connection->execute(
            'UPDATE ' . self::TABLE
            . ' SET [State] = :state, [ResultPublicId] = CONVERT(UNIQUEIDENTIFIER, :resultPublicId),'
            . ' [UpdatedAtUtc] = SYSUTCDATETIME()'
            . ' WHERE [ActorKey] = :actor AND [Operation] = :op AND [IdempotencyKey] = :ikey',
            [
                ':state' => QuoteWriteIdempotencyState::COMPLETED,
                ':resultPublicId' => $resultPublicId,
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
                // NVARCHAR(256), and a class name rather than a message — a
                // message can carry a field value out of the payload that failed.
                ':reason' => mb_substr($failureReason, 0, 256),
                ':actor' => $actorKey,
                ':op' => $operation,
                ':ikey' => $idempotencyKey,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): OnboardingWriteIdempotencyRecord
    {
        $created = AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null) ?? 'now';
        $updated = AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null) ?? $created;
        $utc = new DateTimeZone('UTC');

        return new OnboardingWriteIdempotencyRecord(
            actorKey: AzureSqlValueMapper::stringValue($row['ActorKey'] ?? ''),
            operation: AzureSqlValueMapper::stringValue($row['Operation'] ?? ''),
            idempotencyKey: AzureSqlValueMapper::stringValue($row['IdempotencyKey'] ?? ''),
            requestHash: AzureSqlValueMapper::stringValue($row['RequestHash'] ?? ''),
            state: AzureSqlValueMapper::stringValue($row['State'] ?? QuoteWriteIdempotencyState::IN_PROGRESS),
            platformOperationId: strtolower(trim(AzureSqlValueMapper::stringValue($row['PlatformOperationId'] ?? ''), '{}')),
            resultPublicId: AzureSqlValueMapper::nullableString($row['ResultPublicId'] ?? null),
            createdAtUtc: new DateTimeImmutable($created, $utc),
            updatedAtUtc: new DateTimeImmutable($updated, $utc),
            failureReason: AzureSqlValueMapper::nullableString($row['FailureReason'] ?? null),
        );
    }
}
