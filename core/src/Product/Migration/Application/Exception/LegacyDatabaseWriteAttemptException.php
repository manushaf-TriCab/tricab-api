<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Exception;

use RuntimeException;

/**
 * Thrown when SQL against a legacy production database is not strictly read-only.
 */
final class LegacyDatabaseWriteAttemptException extends RuntimeException
{
    public static function forSql(string $sql): self
    {
        $preview = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        if (strlen($preview) > 200) {
            $preview = substr($preview, 0, 200) . '…';
        }

        return new self(
            'Legacy database write attempt aborted. Only SELECT/SHOW/DESCRIBE/EXPLAIN/INFORMATION_SCHEMA queries are allowed. Rejected SQL: ' . $preview,
        );
    }
}
