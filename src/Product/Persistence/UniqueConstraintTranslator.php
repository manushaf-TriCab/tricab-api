<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use PDOException;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use Throwable;

/**
 * Best-effort translation of SQL Server unique-constraint violations (raised by
 * PdoSqlConnection as DatabaseUnavailable, since the connection boundary cannot
 * know which exception is domain-meaningful) into a caller-supplied, user-safe
 * application exception. Any other underlying failure is rethrown unchanged.
 */
trait UniqueConstraintTranslator
{
    /**
     * @template T
     * @param callable(): T $operation
     * @param callable(): Throwable $onDuplicate
     * @return T
     */
    private function translatingUniqueConstraintViolation(callable $operation, callable $onDuplicate): mixed
    {
        try {
            return $operation();
        } catch (DatabaseUnavailable $exception) {
            if (self::isUniqueConstraintViolation($exception)) {
                throw $onDuplicate();
            }

            throw $exception;
        }
    }

    private static function isUniqueConstraintViolation(DatabaseUnavailable $exception): bool
    {
        $previous = $exception->getPrevious();
        if (!$previous instanceof PDOException) {
            return false;
        }

        if ($previous->getCode() === '23000') {
            return true;
        }

        $errorInfo = $previous->errorInfo ?? null;
        $driverCode = is_array($errorInfo) ? ($errorInfo[1] ?? null) : null;
        if (is_numeric($driverCode) && in_array((int) $driverCode, [2627, 2601], true)) {
            return true;
        }

        return false;
    }
}
