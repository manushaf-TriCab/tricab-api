<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Ap\Domain\ApConflict;

/**
 * What the Tri-AP SQL stores share: the connection, the transaction, the mapping of a
 * unique-index violation to an `ApConflict`, and value coercion. Both stores of one connection
 * join the same transaction, so a use case may write through either.
 */
abstract class AzureSqlApSupport
{
    private const DUPLICATE_KEY_CODES = [2601, 2627];

    public function __construct(protected readonly SqlConnection $connection)
    {
    }

    public function transactional(callable $work): mixed
    {
        if ($this->connection->inTransaction()) {
            return $work();
        }
        $this->connection->beginTransaction();
        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }
    }

    /**
     * A write that may break a unique index: a duplicate becomes an `ApConflict` with the message
     * the use case would have given; anything else is the database's own failure.
     *
     * @param array<string, scalar|null> $params
     */
    protected function write(string $sql, array $params, string $conflictMessage): void
    {
        try {
            $this->connection->execute($sql, $params);
        } catch (DatabaseUnavailable $failure) {
            if (in_array($failure->sqlDriverCode(), self::DUPLICATE_KEY_CODES, true)) {
                throw new ApConflict($conflictMessage, [], $failure);
            }
            throw $failure;
        }
    }

    protected static function at(DateTimeImmutable $value): string
    {
        return AzureSqlValueMapper::formatDateTime($value);
    }

    protected static function atOrNull(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : AzureSqlValueMapper::formatDateTime($value);
    }

    protected static function when(mixed $value, string $field): DateTimeImmutable
    {
        return AzureSqlValueMapper::requireDateTime($value, $field);
    }

    protected static function whenOrNull(mixed $value): ?DateTimeImmutable
    {
        return AzureSqlValueMapper::nullableDateTime($value);
    }

    /** An id column (`CHAR(32)`). */
    protected static function id(mixed $value): string
    {
        return AzureSqlValueMapper::stringValue($value);
    }

    /** A text column as stored: null reads as empty, and nothing is trimmed. */
    protected static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    protected static function textOrNull(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }

    protected static function int(mixed $value): int
    {
        return AzureSqlValueMapper::intValue($value);
    }

    protected static function bool(mixed $value): bool
    {
        return AzureSqlValueMapper::boolValue($value);
    }
}
