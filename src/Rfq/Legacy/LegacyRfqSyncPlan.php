<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

/** What one refresh will do, table by table, keyed by the SHA-256 of each row's primary key. */
final class LegacyRfqSyncPlan
{
    /** Conflicts listed in a report; the counts always cover all of them. */
    public const CONFLICTS_LISTED = 200;

    /** @var array<string, array<string, array{key: list<string>, insert: bool, hash: string}>> */
    private array $writes = [];

    /** @var array<string, array<string, list<string>>> */
    private array $deletes = [];

    /** @var array<string, array<string, string>> rows already equal whose ledger entry is stale */
    private array $ledger = [];

    /** @var array<string, list<string>> ledger entries for rows gone from both sides */
    private array $forget = [];

    /** @var array<string, array<string, int>> */
    private array $kept = [];

    /** @var list<array{table: string, key: string, reason: string}> */
    private array $conflicts = [];

    /** @param list<string> $key */
    public function write(string $table, string $k, array $key, bool $insert, string $hash): void
    {
        $this->writes[$table][$k] = ['key' => $key, 'insert' => $insert, 'hash' => $hash];
    }

    public function unwrite(string $table, string $k): void
    {
        unset($this->writes[$table][$k]);
    }

    public function writes(string $table, string $k): bool
    {
        return isset($this->writes[$table][$k]);
    }

    /** @return array<string, array{key: list<string>, insert: bool, hash: string}> */
    public function writesFor(string $table): array
    {
        return $this->writes[$table] ?? [];
    }

    /** @param list<string> $key */
    public function delete(string $table, string $k, array $key): void
    {
        $this->deletes[$table][$k] = $key;
    }

    public function undelete(string $table, string $k): void
    {
        unset($this->deletes[$table][$k]);
    }

    public function deletes(string $table, string $k): bool
    {
        return isset($this->deletes[$table][$k]);
    }

    /** @return array<string, list<string>> */
    public function deletesFor(string $table): array
    {
        return $this->deletes[$table] ?? [];
    }

    public function ledger(string $table, string $k, string $hash): void
    {
        $this->ledger[$table][$k] = $hash;
    }

    /** @return array<string, string> */
    public function ledgerFor(string $table): array
    {
        return $this->ledger[$table] ?? [];
    }

    public function forget(string $table, string $k): void
    {
        $this->forget[$table][] = $k;
    }

    /** @return list<string> */
    public function forgetFor(string $table): array
    {
        return $this->forget[$table] ?? [];
    }

    public function kept(string $table, string $reason): void
    {
        $this->kept[$table][$reason] = ($this->kept[$table][$reason] ?? 0) + 1;
    }

    /** @param list<string> $key */
    public function conflict(string $table, array $key, string $reason): void
    {
        $this->conflicts[] = ['table' => $table, 'key' => implode('/', $key), 'reason' => $reason];
    }

    public function isEmpty(): bool
    {
        foreach ([$this->writes, $this->deletes, $this->ledger, $this->forget] as $part) {
            foreach ($part as $rows) {
                if ($rows !== []) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array{counts: array<string, array<string, int>>, conflictCount: int, conflicts: list<array{table: string, key: string, reason: string}>}
     */
    public function summary(): array
    {
        $counts = [];
        foreach (array_keys(LegacyRfqTables::TABLES) as $table) {
            $writes = $this->writes[$table] ?? [];
            $row = array_filter([
                'inserted' => count(array_filter($writes, static fn (array $w): bool => $w['insert'])),
                'updated' => count(array_filter($writes, static fn (array $w): bool => !$w['insert'])),
                'deleted' => count($this->deletes[$table] ?? []),
                'platformChangeKept' => $this->kept[$table]['platform changed'] ?? 0,
                'platformDeleteKept' => $this->kept[$table]['platform deleted'] ?? 0,
            ]);
            if ($row !== []) {
                $counts[$table] = $row;
            }
        }

        return [
            'counts' => $counts,
            'conflictCount' => count($this->conflicts),
            'conflicts' => array_slice($this->conflicts, 0, self::CONFLICTS_LISTED),
        ];
    }
}
