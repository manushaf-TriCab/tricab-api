<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Keeps the platform's copy of the old app's RFQ store current until cutover
 * (RFQ plan 7.2-7.4), without undoing work done on the platform.
 *
 * Three versions of every imported row are compared: the source now (S),
 * the platform now (T), and the source as last imported (L, the ledger):
 *
 *  - only tc-web changed it (S ≠ L, T = L): take tc-web's;
 *  - only the platform changed it (S = L, T ≠ L): keep the platform's;
 *  - both changed it: **tc-web wins until cutover**, and the run reports it;
 *  - tc-web deleted it: delete it, unless the platform changed it since;
 *  - a row with no ledger entry was made on the platform and is never
 *    touched, except as below.
 *
 * An RFQ's header and lines are one unit: when tc-web changed any part of an
 * RFQ, its header and exactly its lines are taken, so an RFQ never ends up
 * with some lines from each side. Activity and edit logs are append-only and
 * keep every row from both sides.
 *
 * `refresh()` plans first; with `$apply` it writes in one transaction under
 * an application lock, re-reads every row it is about to touch, and checks
 * every written row against the source before COMMIT.
 */
final class LegacyRfqStoreSync
{
    public const LOCK = 'rfq-legacy-sync';
    private const BATCH = 200;

    /** @var array<string, list<array{name: string, kind: string}>> */
    private array $columns = [];

    public function __construct(private readonly PDO $target)
    {
    }

    /**
     * Start the ledger from the first import. Refuses unless the ledger is
     * empty and every table's row count and digest still equal the import
     * report's, which proves no row has changed since it was loaded.
     *
     * @param array{snapshotAt?: string, tables?: array<string, array{rows: int, sha256: string}>} $report
     *
     * @return array<string, mixed>
     */
    public function adopt(array $report, bool $apply): array
    {
        if ($this->ledgerRows() > 0) {
            throw new LegacyRfqSyncRefused('The ledger already has rows; adopt runs once, on the first import.');
        }
        $tables = $report['tables'] ?? [];
        $hashes = [];
        $problems = [];
        foreach (array_keys(LegacyRfqTables::TABLES) as $table) {
            $expected = $tables[$table] ?? null;
            $rows = $this->targetRows($table);
            $hashes[$table] = array_map(static fn (array $r): string => $r['hash'], $rows);
            // The import report sorted by the key itself, not its hash.
            $byKey = [];
            foreach ($rows as $row) {
                $byKey[implode("\x1f", $row['key'])] = $row['hash'];
            }
            $digest = LegacyRfqRowHash::digest($byKey);
            if ($expected === null) {
                $problems[] = "{$table}: not in the import report";
            } elseif ($expected['rows'] !== count($hashes[$table]) || $expected['sha256'] !== $digest) {
                $problems[] = sprintf('%s: %d rows now, %d imported; digest %s', $table, count($hashes[$table]), $expected['rows'], $expected['sha256'] === $digest ? 'equal' : 'differs');
            }
        }
        if ($problems !== []) {
            throw new LegacyRfqSyncRefused("The platform rows are no longer the import:\n  - " . implode("\n  - ", $problems));
        }
        $counts = array_map('count', $hashes);
        if (!$apply) {
            return ['mode' => 'adopt', 'applied' => false, 'rows' => $counts];
        }

        $runId = bin2hex(random_bytes(16));
        $snapshot = isset($report['snapshotAt']) ? new DateTimeImmutable($report['snapshotAt']) : null;
        $this->target->beginTransaction();
        try {
            $this->lock();
            $this->startRun($runId, 'adopt', $snapshot);
            foreach ($hashes as $table => $rows) {
                $this->ledgerUpsert($table, $rows, $runId);
            }
            $this->finishRun($runId, ['ledger' => $counts], []);
            $this->target->commit();
        } catch (Throwable $e) {
            $this->target->rollBack();
            throw $e;
        }

        return ['mode' => 'adopt', 'applied' => true, 'runId' => $runId, 'rows' => $counts];
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(LegacyRfqSource $source, bool $apply): array
    {
        $started = microtime(true);
        if ($this->ledgerRows() === 0) {
            throw new LegacyRfqSyncRefused('The ledger is empty. Run with --adopt on the first import before refreshing.');
        }
        $this->checkShape($source);
        $ledger = $this->ledger();

        // ---- Read both sides: key sha => [hash, key values, parent id] ----
        $s = $this->sourceRows($source);
        $t = $this->allTargetRows();
        $documentDrafts = [];
        foreach ($this->fetchAll('SELECT [draft_id] FROM [rfq].[rfq_documents]') as $row) {
            $documentDrafts[LegacyRfqRowHash::text($row['draft_id'] ?? '')] = true;
        }

        $plan = new LegacyRfqSyncPlan();

        // ---- RFQs tc-web changed: header or any line --------------------
        $drafts = LegacyRfqTables::DRAFTS;
        $lines = LegacyRfqTables::LINES;
        $changedDrafts = [];
        foreach ($s[$drafts] ?? [] as $k => $row) {
            if (($ledger[$drafts][$k] ?? null) !== $row['hash']) {
                $changedDrafts[$row['key'][0]] = true;
            }
        }
        foreach ($s[$lines] ?? [] as $k => $row) {
            if (($ledger[$lines][$k] ?? null) !== $row['hash']) {
                $changedDrafts[(string) $row['parent']] = true;
            }
        }
        foreach ($ledger[$lines] ?? [] as $k => $_) {
            if (!isset($s[$lines][$k]) && isset($t[$lines][$k])) {
                $changedDrafts[(string) $t[$lines][$k]['parent']] = true;
            }
        }
        $draftIdHash = static fn (string $id): string => hash('sha256', $id);
        // Only RFQs that still exist in tc-web move as a unit; a deleted one is per row.
        $units = array_filter(
            array_keys($changedDrafts),
            static fn (string $id): bool => isset($s[$drafts][$draftIdHash($id)]),
        );
        $units = array_flip(array_map('strval', $units));

        // ---- Decide every key ---------------------------------------------
        foreach (LegacyRfqTables::TABLES as $table => $pk) {
            $L = $ledger[$table] ?? [];
            $S = $s[$table] ?? [];
            $T = $t[$table] ?? [];
            foreach ($S as $k => $src) {
                $inUnit = ($table === $drafts && isset($units[$src['key'][0]])) || ($table === $lines && isset($units[(string) $src['parent']]));
                $tgt = $T[$k] ?? null;
                $last = $L[$k] ?? null;
                if ($tgt !== null && $tgt['hash'] === $src['hash']) {
                    if ($last !== $src['hash']) {
                        $plan->ledger($table, $k, $src['hash']);
                    }
                    continue;
                }
                if (!$inUnit && $last !== null && $last === $src['hash']) {
                    // tc-web has not changed it since it was imported: the platform's change stands.
                    $plan->kept($table, $tgt === null ? 'platform deleted' : 'platform changed');
                    continue;
                }
                if ($tgt !== null && $last !== null && $tgt['hash'] !== $last) {
                    $plan->conflict($table, $src['key'], 'changed on both sides; tc-web wins');
                } elseif ($tgt !== null && $last === null) {
                    $plan->conflict($table, $src['key'], 'made on the platform with the same key; tc-web wins');
                }
                $plan->write($table, $k, $src['key'], $tgt === null, $src['hash']);
            }
            foreach ($T as $k => $tgt) {
                if (isset($S[$k])) {
                    continue;
                }
                $unitLine = $table === $lines && isset($units[(string) $tgt['parent']]);
                if (!isset($L[$k]) && !$unitLine) {
                    continue; // made on the platform
                }
                if ($unitLine || $tgt['hash'] === $L[$k]) {
                    if ($unitLine && (!isset($L[$k]) || $tgt['hash'] !== $L[$k])) {
                        $plan->conflict($table, $tgt['key'], 'a platform line on an RFQ tc-web changed; tc-web\'s lines win');
                    }
                    $plan->delete($table, $k, $tgt['key']);
                } else {
                    $plan->conflict($table, $tgt['key'], 'deleted in tc-web but changed on the platform; kept');
                }
            }
            foreach ($L as $k => $_) {
                if (!isset($S[$k]) && !isset($T[$k])) {
                    $plan->forget($table, $k); // gone from both
                }
            }
        }

        // ---- Foreign keys: never orphan a child, never insert one without a parent
        foreach (LegacyRfqTables::PARENTS as $child => [$column, $parentTable]) {
            $rows = $child === 'rfq_documents'
                ? array_map(static fn (string $d): array => ['parent' => $d], array_map('strval', array_keys($documentDrafts)))
                : ($t[$child] ?? []);
            foreach ($rows as $k => $row) {
                $parentKey = hash('sha256', (string) $row['parent']);
                if ($plan->deletes($parentTable, $parentKey) && ($child === 'rfq_documents' || !$plan->deletes($child, (string) $k))) {
                    $plan->undelete($parentTable, $parentKey);
                    $plan->conflict($parentTable, [(string) $row['parent']], "deleted in tc-web but still has {$child} on the platform; kept");
                }
            }
        }
        foreach (LegacyRfqTables::PARENTS as $child => [$column, $parentTable]) {
            foreach ($plan->writesFor($child) as $k => $_) {
                $parentId = (string) ($s[$child][$k]['parent'] ?? '');
                $parentKey = hash('sha256', $parentId);
                $parentThere = (isset($t[$parentTable][$parentKey]) && !$plan->deletes($parentTable, $parentKey)) || $plan->writes($parentTable, $parentKey);
                if (!$parentThere) {
                    $plan->unwrite($child, $k);
                    $plan->conflict($child, $s[$child][$k]['key'], "its {$parentTable} row is not on the platform; skipped");
                }
            }
        }

        $report = ['mode' => 'refresh', 'applied' => false, 'snapshotAt' => $source->snapshotAt()->format(DATE_ATOM)]
            + $plan->summary() + ['seconds' => ['plan' => round(microtime(true) - $started, 1)]];
        if (!$apply || $plan->isEmpty()) {
            return $report + ['upToDate' => $plan->isEmpty()];
        }

        return $this->apply($source, $plan, $s, $report);
    }

    /**
     * @param array<string, array<string, array{hash: string, key: list<string>, parent: string|null}>> $s
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function apply(LegacyRfqSource $source, LegacyRfqSyncPlan $plan, array $s, array $report): array
    {
        $runId = bin2hex(random_bytes(16));
        $planned = $report['seconds'] ?? null;
        $seconds = ['plan' => is_array($planned) && is_float($planned['plan'] ?? null) ? $planned['plan'] : 0.0];
        $clock = microtime(true);
        $lap = static function (string $phase) use (&$seconds, &$clock): void {
            $seconds[$phase] = round(($seconds[$phase] ?? 0) + microtime(true) - $clock, 1);
            $clock = microtime(true);
        };

        // Read what to write from tc-web before the transaction opens, so a
        // slow source never holds locks on the platform.
        $toWrite = [];
        foreach (LegacyRfqTables::TABLES as $table => $pk) {
            $keys = $plan->writesFor($table);
            if ($keys === []) {
                continue;
            }
            $rows = $source->rowsByKeys($table, array_column($this->columns($table), 'name'), $pk, array_values(array_map(static fn (array $w): array => $w['key'], $keys)));
            if (count($rows) !== count($keys)) {
                throw new RuntimeException("{$table}: the source returned " . count($rows) . ' of ' . count($keys) . ' rows to write.');
            }
            $toWrite[$table] = $rows;
        }
        $lap('sourceRead');

        $this->target->beginTransaction();
        try {
            $this->lock();
            $this->startRun($runId, 'refresh', $source->snapshotAt());

            // Children first.
            foreach (array_reverse(array_keys(LegacyRfqTables::TABLES)) as $table) {
                $keys = $plan->deletesFor($table);
                if ($keys !== []) {
                    $this->deleteRows($table, LegacyRfqTables::TABLES[$table], array_values($keys));
                    $this->ledgerDelete($table, array_keys($keys));
                }
            }
            $lap('delete');
            // Parents first.
            foreach (LegacyRfqTables::TABLES as $table => $pk) {
                $keys = $plan->writesFor($table);
                if ($keys === []) {
                    continue;
                }
                $cols = array_column($this->columns($table), 'name');
                $existing = $this->existingKeys($table, $pk, array_values(array_map(static fn (array $w): array => $w['key'], $keys)));
                $this->writeRows($table, $pk, $cols, $toWrite[$table] ?? [], $existing);
            }
            $lap('write');
            // Ledger, then the check that matters: every written row equals the source's.
            foreach (LegacyRfqTables::TABLES as $table => $pk) {
                $hashes = array_map(static fn (array $w): string => $w['hash'], $plan->writesFor($table)) + $plan->ledgerFor($table);
                $this->ledgerUpsert($table, $hashes, $runId);
                $this->ledgerDelete($table, $plan->forgetFor($table));
                $lap('ledger');
                $written = $plan->writesFor($table);
                if ($written !== []) {
                    $now = $this->targetRows($table, array_values(array_map(static fn (array $w): array => $w['key'], $written)));
                    foreach ($written as $k => $w) {
                        if (($now[$k]['hash'] ?? null) !== $s[$table][$k]['hash']) {
                            throw new RuntimeException("{$table}: row " . implode('/', $w['key']) . ' does not equal the source after writing.');
                        }
                    }
                }
                $lap('verify');
            }
            $summary = $plan->summary();
            $this->finishRun($runId, $summary['counts'], $summary['conflicts']);
            $this->target->commit();
        } catch (Throwable $e) {
            if ($this->target->inTransaction()) {
                $this->target->rollBack();
            }
            $this->failRun($runId, $source->snapshotAt(), $e->getMessage());
            throw $e;
        }

        $lap('commit');

        return ['applied' => true, 'runId' => $runId, 'seconds' => $seconds] + $report;
    }

    // ---- Reads --------------------------------------------------------------

    /**
     * Every source row, as key sha => [hash, key values, parent id].
     *
     * @return array<string, array<string, array{hash: string, key: list<string>, parent: string|null}>>
     */
    private function sourceRows(LegacyRfqSource $source): array
    {
        $rows = [];
        foreach (LegacyRfqTables::TABLES as $table => $pk) {
            $cols = $this->columns($table);
            $rows[$table] = [];
            foreach ($source->rows($table, array_column($cols, 'name'), $pk) as $row) {
                $rows[$table][hash('sha256', LegacyRfqRowHash::key($row, $pk))] = self::info($table, $row, $cols);
            }
        }

        return $rows;
    }

    /** @return array<string, array<string, array{hash: string, key: list<string>, parent: string|null}>> */
    private function allTargetRows(): array
    {
        $rows = [];
        foreach (array_keys(LegacyRfqTables::TABLES) as $table) {
            $rows[$table] = $this->targetRows($table);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{name: string, kind: string}> $cols
     *
     * @return array{hash: string, key: list<string>, parent: string|null}
     */
    private static function info(string $table, array $row, array $cols): array
    {
        $parent = LegacyRfqTables::PARENTS[$table][0] ?? null;

        return [
            'hash' => LegacyRfqRowHash::row($row, $cols),
            'key' => array_map(static fn (string $c): string => rtrim(LegacyRfqRowHash::text($row[$c] ?? ''), ' '), LegacyRfqTables::TABLES[$table] ?? []),
            'parent' => $parent === null ? null : rtrim(LegacyRfqRowHash::text($row[$parent] ?? ''), ' '),
        ];
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->target->prepare($sql);
        $statement->execute($params);
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $named = [];
            foreach ($row as $column => $value) {
                $named[(string) $column] = $value;
            }
            $rows[] = $named;
        }

        return $rows;
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->target->query($sql);

        return $statement === false ? null : $statement->fetchColumn();
    }

    /** @return list<array{name: string, kind: string}> */
    private function columns(string $table): array
    {
        if (!isset($this->columns[$table])) {
            $statement = $this->target->prepare(
                "SELECT c.[name], t.[name] AS [type] FROM sys.columns c JOIN sys.types t ON t.[user_type_id] = c.[user_type_id]
                 WHERE c.[object_id] = OBJECT_ID(?, N'U') ORDER BY c.[column_id]",
            );
            $statement->execute(["[rfq].[{$table}]"]);
            $columns = [];
            $skip = LegacyRfqTables::PLATFORM_ONLY[$table] ?? [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (in_array($row['name'] ?? '', $skip, true)) {
                    continue;
                }
                $columns[] = ['name' => LegacyRfqRowHash::text($row['name'] ?? ''), 'type' => LegacyRfqRowHash::text($row['type'] ?? '')];
            }
            if ($columns === []) {
                throw new LegacyRfqSyncRefused("[rfq].[{$table}] is missing on the platform.");
            }
            $this->columns[$table] = LegacyRfqRowHash::kinds($columns);
        }

        return $this->columns[$table];
    }

    private function checkShape(LegacyRfqSource $source): void
    {
        $problems = [];
        foreach (array_keys(LegacyRfqTables::TABLES) as $table) {
            $target = array_column($this->columns($table), 'name');
            $sourceColumns = $source->columns($table);
            if ($sourceColumns !== $target) {
                $problems[] = sprintf(
                    '%s: source only: %s; platform only: %s%s',
                    $table,
                    implode(', ', array_diff($sourceColumns, $target)) ?: '-',
                    implode(', ', array_diff($target, $sourceColumns)) ?: '-',
                    array_diff($sourceColumns, $target) === [] && array_diff($target, $sourceColumns) === [] ? ' (order differs)' : '',
                );
            }
        }
        if ($problems !== []) {
            throw new LegacyRfqSyncRefused("The source's tables no longer match the platform's:\n  - " . implode("\n  - ", $problems));
        }
    }

    /**
     * Platform rows of a table, all or only these keys: key sha => [hash, key, parent].
     *
     * @param list<list<string>>|null $only
     *
     * @return array<string, array{hash: string, key: list<string>, parent: string|null}>
     */
    private function targetRows(string $table, ?array $only = null): array
    {
        $cols = $this->columns($table);
        $pk = LegacyRfqTables::TABLES[$table] ?? [];
        $select = 'SELECT [' . implode('], [', array_column($cols, 'name')) . "] FROM [rfq].[{$table}]";
        $batches = $only === null ? [null] : array_chunk($only, self::BATCH);
        $rows = [];
        foreach ($batches as $batch) {
            [$where, $params] = $batch === null ? ['', []] : self::keyPredicate($pk, $batch);
            foreach ($this->fetchAll($select . $where, $params) as $row) {
                $rows[hash('sha256', LegacyRfqRowHash::key($row, $pk))] = self::info($table, $row, $cols);
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $pk
     * @param list<list<string>> $keys
     *
     * @return array<string, true> key sha => true for the keys already on the platform
     */
    private function existingKeys(string $table, array $pk, array $keys): array
    {
        $found = [];
        foreach (array_chunk($keys, self::BATCH) as $batch) {
            [$where, $params] = self::keyPredicate($pk, $batch);
            foreach ($this->fetchAll('SELECT [' . implode('], [', $pk) . "] FROM [rfq].[{$table}] WITH (UPDLOCK, HOLDLOCK)" . $where, $params) as $row) {
                $found[hash('sha256', LegacyRfqRowHash::key($row, $pk))] = true;
            }
        }

        return $found;
    }

    // ---- Target writes ----------------------------------------------------

    /**
     * @param list<string> $pk
     * @param list<string> $cols
     * @param list<array<string, mixed>> $rows
     * @param array<string, true> $existing
     */
    private function writeRows(string $table, array $pk, array $cols, array $rows, array $existing): void
    {
        $others = array_values(array_diff($cols, $pk));
        $inserts = array_values(array_filter($rows, static fn (array $row): bool => !isset($existing[hash('sha256', LegacyRfqRowHash::key($row, $pk))])));
        $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($existing[hash('sha256', LegacyRfqRowHash::key($row, $pk))])));
        // Many rows per INSERT, under SQL Server's 2,100-parameter limit: one round trip per batch, not per row.
        $perStatement = max(1, min(self::BATCH, intdiv(2000, count($cols))));
        foreach (array_chunk($inserts, $perStatement) as $batch) {
            $statement = $this->target->prepare(
                "INSERT INTO [rfq].[{$table}] ([" . implode('], [', $cols) . ']) VALUES '
                . implode(', ', array_fill(0, count($batch), '(' . implode(', ', array_fill(0, count($cols), '?')) . ')')),
            );
            $position = 1;
            foreach ($batch as $row) {
                foreach ($cols as $c) {
                    $value = $row[$c];
                    $statement->bindValue($position++, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                }
            }
            $statement->execute();
        }
        $update = $others === [] ? null : $this->target->prepare(
            "UPDATE [rfq].[{$table}] SET " . implode(', ', array_map(static fn (string $c): string => "[{$c}] = ?", $others))
            . ' WHERE ' . implode(' AND ', array_map(static fn (string $c): string => "[{$c}] = ?", $pk)),
        );
        if ($update === null || $update === false) {
            return; // nothing but the key, which already matches
        }
        foreach ($rows as $row) {
            $values = [...array_map(static fn (string $c) => $row[$c], $others), ...array_map(static fn (string $c) => $row[$c], $pk)];
            foreach ($values as $i => $value) {
                $update->bindValue($i + 1, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
            $update->execute();
        }
    }

    /**
     * @param list<string> $pk
     * @param list<list<string>> $keys
     */
    private function deleteRows(string $table, array $pk, array $keys): void
    {
        foreach (array_chunk($keys, self::BATCH) as $batch) {
            [$where, $params] = self::keyPredicate($pk, $batch);
            $this->target->prepare("DELETE FROM [rfq].[{$table}]" . $where)->execute($params);
        }
    }

    /**
     * @param list<string> $pk
     * @param list<list<string>> $keys
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function keyPredicate(array $pk, array $keys): array
    {
        if (count($pk) === 1) {
            return [" WHERE [{$pk[0]}] IN (" . implode(', ', array_fill(0, count($keys), '?')) . ')', array_map(static fn (array $k): string => $k[0], $keys)];
        }
        $match = '(' . implode(' AND ', array_map(static fn (string $c): string => "[{$c}] = ?", $pk)) . ')';

        return [' WHERE ' . implode(' OR ', array_fill(0, count($keys), $match)), array_merge(...$keys)];
    }

    // ---- Ledger and runs --------------------------------------------------

    private function ledgerRows(): int
    {
        if ($this->scalar("SELECT OBJECT_ID(N'[rfq].[rfq_legacy_import_rows]', N'U')") === null) {
            throw new LegacyRfqSyncRefused('The ledger tables are missing. Run create-rfq-legacy-import-ledger.sql.');
        }

        return (int) LegacyRfqRowHash::text($this->scalar('SELECT COUNT_BIG(*) FROM [rfq].[rfq_legacy_import_rows]'));
    }

    /** @return array<string, array<string, string>> table => key sha => row sha */
    private function ledger(): array
    {
        $ledger = [];
        foreach ($this->fetchAll('SELECT [table_name], [key_sha256], [row_sha256] FROM [rfq].[rfq_legacy_import_rows]') as $row) {
            $ledger[LegacyRfqRowHash::text($row['table_name'] ?? '')][LegacyRfqRowHash::text($row['key_sha256'] ?? '')] = LegacyRfqRowHash::text($row['row_sha256'] ?? '');
        }

        return $ledger;
    }

    /** @param array<string, string> $hashes key sha => row sha */
    private function ledgerUpsert(string $table, array $hashes, string $runId): void
    {
        if ($hashes === []) {
            return;
        }
        $this->ledgerDelete($table, array_map('strval', array_keys($hashes)));
        foreach (array_chunk($hashes, 500, true) as $batch) {
            $params = [];
            foreach ($batch as $key => $hash) {
                array_push($params, $table, (string) $key, $hash, $runId);
            }
            $this->target->prepare(
                'INSERT INTO [rfq].[rfq_legacy_import_rows] ([table_name], [key_sha256], [row_sha256], [run_id]) VALUES '
                . implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?)')),
            )->execute($params);
        }
    }

    /** @param list<string> $keys */
    private function ledgerDelete(string $table, array $keys): void
    {
        foreach (array_chunk($keys, 500) as $batch) {
            $this->target->prepare(
                'DELETE FROM [rfq].[rfq_legacy_import_rows] WHERE [table_name] = ? AND [key_sha256] IN (' . implode(', ', array_fill(0, count($batch), '?')) . ')',
            )->execute([$table, ...$batch]);
        }
    }

    private function lock(): void
    {
        // Direct, not prepared: sp_prepare does not take a batch that declares a variable.
        $statement = $this->target->query(
            "SET NOCOUNT ON; DECLARE @r INT; EXEC @r = sp_getapplock @Resource = N'" . self::LOCK . "', @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 0; SELECT @r AS [r];",
        );
        if ($statement === false) {
            throw new RuntimeException('Could not ask for the refresh lock.');
        }
        $result = null;
        do {
            try {
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && array_key_exists('r', $row)) {
                    $result = (int) LegacyRfqRowHash::text($row['r']);
                }
            } catch (\PDOException) {
                // a rowset with no columns
            }
        } while ($result === null && $statement->nextRowset());
        if ($result === null || $result < 0) {
            throw new LegacyRfqSyncBusy('Another refresh holds the lock.');
        }
    }

    private function startRun(string $runId, string $mode, ?DateTimeImmutable $snapshot): void
    {
        $this->target->prepare(
            "INSERT INTO [rfq].[rfq_legacy_import_runs] ([id], [mode], [started_at], [source_snapshot_at], [status]) VALUES (?, ?, SYSUTCDATETIME(), ?, N'committed')",
        )->execute([$runId, $mode, $snapshot?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v')]);
    }

    /**
     * @param array<string, mixed> $counts
     * @param list<array<string, mixed>> $conflicts
     */
    private function finishRun(string $runId, array $counts, array $conflicts): void
    {
        $this->target->prepare('UPDATE [rfq].[rfq_legacy_import_runs] SET [finished_at] = SYSUTCDATETIME(), [counts] = ?, [conflicts] = ? WHERE [id] = ?')
            ->execute([json_encode($counts, JSON_THROW_ON_ERROR), json_encode($conflicts, JSON_THROW_ON_ERROR), $runId]);
    }

    private function failRun(string $runId, DateTimeImmutable $snapshot, string $error): void
    {
        try {
            $this->target->prepare(
                "INSERT INTO [rfq].[rfq_legacy_import_runs] ([id], [mode], [started_at], [finished_at], [source_snapshot_at], [status], [conflicts])
                 VALUES (?, N'refresh', SYSUTCDATETIME(), SYSUTCDATETIME(), ?, N'failed', ?)",
            )->execute([$runId, $snapshot->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'), json_encode(['error' => mb_substr($error, 0, 2000)], JSON_THROW_ON_ERROR)]);
        } catch (Throwable) {
            // Recording the failure must not hide it.
        }
    }
}
