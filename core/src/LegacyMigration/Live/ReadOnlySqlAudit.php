<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;

/**
 * Classifies SQL for strict read-only analysis tasks.
 */
final class ReadOnlySqlAudit
{
    private int $legacySelectCount = 0;
    private int $azureSelectCount = 0;
    private int $legacyWriteAttempts = 0;
    private int $azureWriteAttempts = 0;

    public function recordLegacy(string $sql): void
    {
        if ($this->isWrite($sql)) {
            ++$this->legacyWriteAttempts;
            throw LegacyDatabaseWriteAttemptException::forSql($sql);
        }
        ++$this->legacySelectCount;
    }

    public function recordAzure(string $sql): void
    {
        if ($this->isWrite($sql)) {
            ++$this->azureWriteAttempts;
            throw new \RuntimeException('Azure SQL write attempted during strict read-only staff analysis.');
        }
        ++$this->azureSelectCount;
    }

    /** @return array<string, int|bool> */
    public function summary(): array
    {
        return [
            'legacySelectQueries' => $this->legacySelectCount,
            'azureSelectQueries' => $this->azureSelectCount,
            'legacyInsert' => 0,
            'legacyUpdate' => 0,
            'legacyDelete' => 0,
            'legacyDdl' => 0,
            'azureInsert' => 0,
            'azureUpdate' => 0,
            'azureDelete' => 0,
            'azureMerge' => 0,
            'azureDdl' => 0,
            'legacyWriteAttemptsBlocked' => $this->legacyWriteAttempts,
            'azureWriteAttemptsBlocked' => $this->azureWriteAttempts,
            'identityUsersModified' => 0,
            'externalIdentitiesModified' => 0,
            'userRolesModified' => 0,
            'customerContactUsersModified' => 0,
            'migrationStoreModified' => 0,
        ];
    }

    private function isWrite(string $sql): bool
    {
        $trimmed = ltrim($sql);
        if ($trimmed === '') {
            return true;
        }
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|MERGE|CALL|GRANT|REVOKE|LOAD\s+DATA|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $trimmed) === 1) {
            return true;
        }

        return false;
    }
}
