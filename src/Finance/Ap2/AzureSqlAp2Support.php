<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use DateTimeImmutable;
use Throwable;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/**
 * What the Tri-AP SQL stores share: the connection, the transaction, the mapping of a unique-index violation to an
 * `Ap2Conflict`, value coercion, money, calendar days and people. Every store of one connection joins the same
 * transaction, so a use case may write through any of them.
 */
abstract class AzureSqlAp2Support
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
     * A write that may break a unique index: a duplicate becomes an `Ap2Conflict` with the message
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
                throw new Ap2Conflict($conflictMessage, [], $failure);
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

    protected static function amount(mixed $value): float
    {
        return is_scalar($value) ? (float) $value : 0.0;
    }

    protected static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    protected static function quantity(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    protected static function day(mixed $value): ?string
    {
        $text = self::textOrNull($value);

        return $text === null || $text === '' ? null : substr($text, 0, 10);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected static function person(array $row, string $prefix): ?Ap2Person
    {
        $userId = AzureSqlValueMapper::nullableInt($row[$prefix . '_user_id'] ?? null);

        return $userId === null ? null : new Ap2Person($userId, self::text($row[$prefix . '_name'] ?? ''), self::text($row[$prefix . '_email'] ?? ''));
    }
}
