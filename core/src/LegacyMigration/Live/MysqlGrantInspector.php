<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

/**
 * Inspects MySQL SHOW GRANTS rows without returning the raw grant strings.
 */
final class MysqlGrantInspector
{
    /** @var list<string> */
    private const WRITE_TOKENS = [
        'ALL PRIVILEGES',
        'ALL,',
        ' ALL ',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'DROP',
        'ALTER',
        'GRANT OPTION',
        'REFERENCES',
        'INDEX',
        'TRIGGER',
        'EVENT',
        'EXECUTE',
        'FILE',
        'SUPER',
        'RELOAD',
        'SHUTDOWN',
        'PROCESS',
        'CREATE USER',
        'REPLACE',
        'TRUNCATE',
    ];

    /**
     * @param list<array<string, mixed>> $grantRows
     */
    public function isSelectOnly(array $grantRows): bool
    {
        if ($grantRows === []) {
            return false;
        }

        $sawSelect = false;
        foreach ($grantRows as $row) {
            $grant = strtoupper(implode(' ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $row)));
            if (str_contains($grant, 'SELECT')) {
                $sawSelect = true;
            }
            foreach (self::WRITE_TOKENS as $token) {
                if (str_contains($grant, $token)) {
                    return false;
                }
            }
        }

        return $sawSelect;
    }
}
