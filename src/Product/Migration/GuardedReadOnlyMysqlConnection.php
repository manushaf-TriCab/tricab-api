<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use PDO;
use PDOException;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LegacySqlQueryLogEntry;
use TriCab\Core\Product\Migration\Live\LegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class GuardedReadOnlyMysqlConnection implements LegacyReadOnlyConnection
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LegacyReadOnlySqlGuard $guard,
        private readonly LegacySqlStatementLogger $logger,
        private readonly string $label,
    ) {
    }

    public function sourceLabel(): string
    {
        return $this->label;
    }

    public function query(
        string $sql,
        array $params = [],
        string $repository = 'unknown',
        string $method = 'unknown',
    ): array {
        $this->guard->assertReadOnly($sql);

        // Extra defense: reject stacked statements even if somehow passed the guard.
        if (str_contains($sql, ';') && !str_ends_with(rtrim($sql), ';')) {
            throw LegacyDatabaseWriteAttemptException::forSql($sql);
        }
        $trimmed = rtrim($sql);
        if (substr_count($trimmed, ';') > 1 || (str_ends_with($trimmed, ';') && substr_count($trimmed, ';') > 1)) {
            throw LegacyDatabaseWriteAttemptException::forSql($sql);
        }
        if (str_ends_with($trimmed, ';')) {
            $sql = substr($trimmed, 0, -1);
            $this->guard->assertReadOnly($sql);
        }

        $started = hrtime(true);
        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        try {
            $statement = $this->pdo->prepare($sql);
            foreach ($params as $name => $value) {
                $key = is_string($name) && !str_starts_with($name, ':') ? ':' . $name : (string) $name;
                if ($value === null) {
                    $statement->bindValue($key, null, PDO::PARAM_NULL);
                } elseif (is_bool($value)) {
                    $statement->bindValue($key, $value, PDO::PARAM_BOOL);
                } elseif (is_int($value)) {
                    $statement->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $statement->bindValue($key, MigrationPayload::string($value));
                }
            }
            $statement->execute();
            $fetched = $statement->fetchAll(PDO::FETCH_ASSOC);
            $rows = is_array($fetched) ? $fetched : [];
        } catch (PDOException $e) {
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;
            $this->logger->log(new LegacySqlQueryLogEntry(
                timestampUtc: gmdate('Y-m-d\TH:i:s\Z'),
                repository: $repository,
                method: $method,
                sql: $sql,
                executionTimeMs: round($elapsedMs, 3),
                rowsReturned: 0,
            ));
            throw $e;
        }

        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $this->logger->log(new LegacySqlQueryLogEntry(
            timestampUtc: gmdate('Y-m-d\TH:i:s\Z'),
            repository: $repository,
            method: $method,
            sql: $sql,
            executionTimeMs: round($elapsedMs, 3),
            rowsReturned: count($rows),
        ));

        return $rows;
    }
}
