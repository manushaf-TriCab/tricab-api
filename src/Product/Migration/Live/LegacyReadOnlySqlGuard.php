<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;

/**
 * Application-level interceptor: legacy production DBs may only run reporting SQL.
 */
final class LegacyReadOnlySqlGuard
{
    private const FORBIDDEN_PREFIXES = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'ALTER', 'CREATE', 'DROP',
        'RENAME', 'LOCK', 'UNLOCK', 'CALL', 'GRANT', 'REVOKE', 'SET', 'LOAD', 'HANDLER',
        'DO', 'MERGE', 'UPSERT', 'FLUSH', 'OPTIMIZE', 'REPAIR', 'ANALYZE', 'CHECKSUM',
        'INSTALL', 'UNINSTALL', 'SHUTDOWN', 'KILL', 'PURGE', 'RESET', 'START', 'STOP',
        'CHANGE', 'PREPARE', 'EXECUTE', 'DEALLOCATE', 'XA',
    ];

    /**
     * @throws LegacyDatabaseWriteAttemptException
     */
    public function assertReadOnly(string $sql): void
    {
        $normalized = $this->normalize($sql);
        if ($normalized === '') {
            throw LegacyDatabaseWriteAttemptException::forSql($sql);
        }

        if ($this->isAllowed($normalized)) {
            return;
        }

        throw LegacyDatabaseWriteAttemptException::forSql($sql);
    }

    public function isAllowed(string $normalizedSql): bool
    {
        $normalized = $this->normalize($normalizedSql);
        if ($normalized === '') {
            return false;
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix . ' ') || $normalized === $prefix) {
                return false;
            }
        }

        if (str_contains($normalized, ' FOR UPDATE')
            || str_contains($normalized, ' FOR SHARE')
            || str_contains($normalized, ' INTO OUTFILE')
            || str_contains($normalized, ' INTO DUMPFILE')
            || str_contains($normalized, ' INTO ') && str_starts_with($normalized, 'SELECT ')
        ) {
            // SELECT … INTO / FOR UPDATE are not read-only reporting queries.
            if (str_contains($normalized, ' FOR UPDATE')
                || str_contains($normalized, ' FOR SHARE')
                || str_contains($normalized, ' INTO OUTFILE')
                || str_contains($normalized, ' INTO DUMPFILE')
                || (str_starts_with($normalized, 'SELECT ') && preg_match('/\bINTO\b/', $normalized) === 1)
            ) {
                return false;
            }
        }

        if (str_starts_with($normalized, 'SELECT ')
            || str_starts_with($normalized, 'SHOW ')
            || str_starts_with($normalized, 'DESCRIBE ')
            || str_starts_with($normalized, 'DESC ')
            || str_starts_with($normalized, 'EXPLAIN ')
        ) {
            return true;
        }

        // INFORMATION_SCHEMA is normally reached via SELECT; also allow bare mentions after WITH.
        if (str_starts_with($normalized, 'WITH ') && str_contains($normalized, ' SELECT ')) {
            return !$this->containsForbiddenKeyword($normalized);
        }

        return false;
    }

    private function containsForbiddenKeyword(string $normalized): bool
    {
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (preg_match('/\b' . preg_quote($prefix, '/') . '\b/', $normalized) === 1) {
                // SELECT … FOR UPDATE etc.
                if ($prefix === 'UPDATE' && str_contains($normalized, ' FOR UPDATE')) {
                    return true;
                }
                if (in_array($prefix, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'ALTER', 'CREATE', 'DROP', 'LOCK', 'CALL', 'SET'], true)) {
                    if (preg_match('/\b' . preg_quote($prefix, '/') . '\b/', $normalized) === 1
                        && !str_starts_with($normalized, 'SELECT ')
                        && !str_starts_with($normalized, 'WITH ')
                    ) {
                        return true;
                    }
                    if (str_contains($normalized, ' FOR UPDATE') || str_contains($normalized, ' INTO OUTFILE') || str_contains($normalized, ' INTO DUMPFILE')) {
                        return true;
                    }
                }
            }
        }

        return str_contains($normalized, ' FOR UPDATE')
            || str_contains($normalized, ' INTO OUTFILE')
            || str_contains($normalized, ' INTO DUMPFILE')
            || str_contains($normalized, ' FOR SHARE');
    }

    public function normalize(string $sql): string
    {
        $withoutComments = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        $withoutComments = preg_replace('/--.*?(\n|$)/', ' ', $withoutComments) ?? $withoutComments;
        $withoutComments = preg_replace('/#.*?(\n|$)/', ' ', $withoutComments) ?? $withoutComments;
        $collapsed = preg_replace('/\s+/', ' ', trim($withoutComments)) ?? trim($withoutComments);

        return strtoupper($collapsed);
    }
}
