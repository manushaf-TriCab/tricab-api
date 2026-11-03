<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Legacy;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Rfq\Legacy\LegacyRfqRowHash;
use TriCab\Api\Rfq\Legacy\LegacyRfqStoreSync;
use TriCab\Api\Rfq\Legacy\LegacyRfqSyncRefused;
use TriCab\Api\Rfq\Legacy\LegacyRfqTables;
use TriCab\Api\Tests\Rfq\Persistence\LocalRfqSqlServer;

/**
 * The refresh's three-way rule, against a real SQL Server: the platform's
 * rows, the ledger from adopt, and an in-memory stand-in for tc-web.
 *
 * Needs a local SQL Server ({@see LocalRfqSqlServer}); skips without one.
 */
#[Group('local-sql-server')]
final class LegacyRfqStoreSyncTest extends TestCase
{
    private PDO $pdo;
    private InMemoryLegacyRfqSource $source;

    protected function setUp(): void
    {
        $pdo = LocalRfqSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set RFQ_TEST_SQLSERVER and RFQ_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalRfqSqlServer::truncate($pdo);

        $columns = [];
        foreach (array_keys(LegacyRfqTables::TABLES) as $table) {
            $columns[(string) $table] = array_values(array_diff($this->texts("SELECT [name] FROM sys.columns WHERE [object_id] = OBJECT_ID(N'[rfq].[{$table}]') ORDER BY [column_id]"), LegacyRfqTables::PLATFORM_ONLY[$table] ?? []));
        }
        $this->source = new InMemoryLegacyRfqSource($columns);

        // The first import: tc-web and the platform hold the same rows.
        foreach (['A', 'B', 'C', 'D', 'E'] as $draft) {
            $this->both('rfq_draft_quotes', $this->draft($draft));
        }
        $this->both('rfq_draft_rows', $this->line('a1', 'A', 1));
        $this->both('rfq_draft_rows', $this->line('a2', 'A', 2));
        $this->both('rfq_draft_rows', $this->line('b1', 'B', 1));
        $this->both('rfq_draft_rows', $this->line('d1', 'D', 1));
        $this->both('rfq_draft_activity', $this->event('ev1', 'A', 'claimed'));
        $this->both('rfq_draft_activity', $this->event('ev2', 'D', 'claimed'));
        $this->both('rfq_correction_memory', ['normalized_text' => '4c 2.5mm', 'product_code' => 'TC425', 'confirmations' => 1, 'created_at' => 't0', 'updated_at' => 't0']);
    }

    public function testRefreshTakesTcWebChangesAndKeepsPlatformWork(): void
    {
        $sync = new LegacyRfqStoreSync($this->pdo);
        self::assertSame(['applied' => true], array_intersect_key($sync->adopt($this->report(), true), ['applied' => 1]));
        self::assertTrue($sync->refresh($this->source, false)['upToDate'], 'nothing changed since the import');

        // tc-web: A gets a new label, line a3 and loses a2; F is new; D is deleted with its line and event; memory confirmed again.
        $this->source->put('rfq_draft_quotes', ['id'], $this->draft('A', ['label' => 'A from tc-web', 'revision' => 2]));
        $this->source->put('rfq_draft_rows', ['id'], $this->line('a3', 'A', 3));
        $this->source->remove('rfq_draft_rows', $this->id('a2'));
        $this->source->put('rfq_draft_quotes', ['id'], $this->draft('F'));
        $this->source->put('rfq_draft_rows', ['id'], $this->line('f1', 'F', 1));
        $this->source->remove('rfq_draft_rows', $this->id('d1'));
        $this->source->remove('rfq_draft_activity', $this->id('ev2'));
        $this->source->remove('rfq_draft_quotes', $this->id('D'));
        $this->source->put('rfq_correction_memory', ['normalized_text', 'product_code'], ['normalized_text' => '4c 2.5mm', 'product_code' => 'TC425', 'confirmations' => 2, 'created_at' => 't0', 'updated_at' => 't1']);
        // tc-web and the platform both change C; tc-web deletes E, which the platform has worked on.
        $this->source->put('rfq_draft_quotes', ['id'], $this->draft('C', ['label' => 'C from tc-web']));
        $this->source->remove('rfq_draft_quotes', $this->id('E'));

        // The platform: B relabelled, its own RFQ P with a line, a claim on A, an extra line on A, a claim on E, C relabelled.
        $this->exec("UPDATE [rfq].[rfq_draft_quotes] SET [label] = N'B on the platform' WHERE [id] = '{$this->id('B')}'");
        $this->insert('rfq_draft_quotes', $this->draft('P'));
        $this->insert('rfq_draft_rows', $this->line('p1', 'P', 1));
        $this->insert('rfq_draft_activity', $this->event('pev', 'A', 'claimed'));
        $this->insert('rfq_draft_rows', $this->line('ax', 'A', 9));
        $this->insert('rfq_draft_activity', $this->event('eev', 'E', 'claimed'));
        $this->exec("UPDATE [rfq].[rfq_draft_quotes] SET [label] = N'C on the platform' WHERE [id] = '{$this->id('C')}'");

        $plan = $sync->refresh($this->source, false);
        self::assertFalse($plan['applied']);
        self::assertSame('A', $this->label('A') === 'A' ? 'A' : 'changed', 'a dry run writes nothing');

        $done = $sync->refresh($this->source, true);
        self::assertTrue($done['applied']);

        self::assertSame('A from tc-web', $this->label('A'), 'tc-web changed A');
        self::assertSame(['a1', 'a3'], $this->lines('A'), 'A has exactly tc-web\'s lines: a2 gone, a3 added, the platform\'s ax removed');
        self::assertSame('B on the platform', $this->label('B'), 'only the platform changed B');
        self::assertSame('C from tc-web', $this->label('C'), 'both changed C: tc-web wins');
        self::assertNull($this->label('D'), 'tc-web deleted D');
        self::assertSame([], $this->lines('D'));
        self::assertSame('E', $this->label('E'), 'E has platform activity, so it is kept');
        self::assertSame('F', $this->label('F'));
        self::assertSame(['f1'], $this->lines('F'));
        self::assertSame('P', $this->label('P'), 'the platform\'s own RFQ is untouched');
        self::assertSame(['p1'], $this->lines('P'));
        self::assertSame(['ev1', 'pev'], $this->events('A'), 'activity from both sides is kept');
        self::assertSame(['2'], $this->texts('SELECT [confirmations] FROM [rfq].[rfq_correction_memory]'));

        self::assertIsArray($done['conflicts']);
        $conflicts = [];
        foreach ($done['conflicts'] as $c) {
            self::assertIsArray($c);
            $conflicts[] = LegacyRfqRowHash::text($c['table']) . ' ' . LegacyRfqRowHash::text($c['reason']);
        }
        sort($conflicts);
        self::assertSame([
            'rfq_draft_quotes changed on both sides; tc-web wins',
            'rfq_draft_quotes deleted in tc-web but still has rfq_draft_activity on the platform; kept',
            "rfq_draft_rows a platform line on an RFQ tc-web changed; tc-web's lines win",
        ], $conflicts);
        $counts = $done['counts'] ?? null;
        self::assertIsArray($counts);
        $draftCounts = $counts['rfq_draft_quotes'] ?? null;
        self::assertIsArray($draftCounts);
        self::assertSame(1, $draftCounts['platformChangeKept'] ?? null, 'B: only the platform changed it');

        // Idempotent, and the run is recorded.
        self::assertTrue($sync->refresh($this->source, false)['upToDate'], 'a second refresh has nothing to do');
        self::assertSame(['2'], $this->texts("SELECT COUNT(*) FROM [rfq].[rfq_legacy_import_runs] WHERE [status] = N'committed'"));

        // A later tc-web change to B now wins over the platform's earlier one.
        $this->source->put('rfq_draft_quotes', ['id'], $this->draft('B', ['label' => 'B from tc-web, later']));
        $sync->refresh($this->source, true);
        self::assertSame('B from tc-web, later', $this->label('B'));
    }

    public function testAdoptRefusesRowsThatAreNoLongerTheImport(): void
    {
        $sync = new LegacyRfqStoreSync($this->pdo);
        $this->exec("UPDATE [rfq].[rfq_draft_quotes] SET [label] = N'changed' WHERE [id] = '{$this->id('A')}'");

        $this->expectException(LegacyRfqSyncRefused::class);
        $this->expectExceptionMessageMatches('/rfq_draft_quotes: 5 rows now, 5 imported; digest differs/');
        $sync->adopt($this->report(), true);
    }

    public function testRefreshRefusesWithoutALedgerOrWithChangedTables(): void
    {
        $sync = new LegacyRfqStoreSync($this->pdo);
        try {
            $sync->refresh($this->source, false);
            self::fail('Refreshed with no ledger.');
        } catch (LegacyRfqSyncRefused $e) {
            self::assertStringContainsString('--adopt', $e->getMessage());
        }

        $sync->adopt($this->report(), true);
        $this->source->columns['rfq_draft_quotes'][] = 'new_column';
        $this->expectException(LegacyRfqSyncRefused::class);
        $this->expectExceptionMessageMatches('/rfq_draft_quotes: source only: new_column/');
        $sync->refresh($this->source, false);
    }

    /** @return array{snapshotAt: string, tables: array<string, array{rows: int, sha256: string}>} */
    private function report(): array
    {
        $tables = [];
        foreach (LegacyRfqTables::TABLES as $table => $pk) {
            $names = $this->texts("SELECT c.[name] FROM sys.columns c WHERE c.[object_id] = OBJECT_ID(N'[rfq].[{$table}]') ORDER BY c.[column_id]");
            $types = $this->texts("SELECT t.[name] FROM sys.columns c JOIN sys.types t ON t.[user_type_id] = c.[user_type_id] WHERE c.[object_id] = OBJECT_ID(N'[rfq].[{$table}]') ORDER BY c.[column_id]");
            $cols = LegacyRfqRowHash::kinds(array_values(array_filter(
                array_map(static fn (string $n, string $t): array => ['name' => $n, 'type' => $t], $names, $types),
                static fn (array $c): bool => !in_array($c['name'], LegacyRfqTables::PLATFORM_ONLY[$table] ?? [], true),
            )));
            $hashes = [];
            foreach ($this->source->rows[$table] ?? [] as $row) {
                $hashes[LegacyRfqRowHash::key($row, $pk)] = LegacyRfqRowHash::row($row, $cols);
            }
            $tables[$table] = ['rows' => count($hashes), 'sha256' => LegacyRfqRowHash::digest($hashes)];
        }

        return ['snapshotAt' => '2026-10-20T01:35:51Z', 'tables' => $tables];
    }

    private function id(string $name): string
    {
        return str_pad(strtolower(bin2hex($name)), 32, '0');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function draft(string $name, array $overrides = []): array
    {
        $row = array_fill_keys($this->source->columns['rfq_draft_quotes'], null);

        return array_merge($row, [
            'id' => $this->id($name), 'created_at' => '2026-10-01T00:00:00+00:00', 'source' => 'web', 'label' => $name,
            'status' => 'new', 'quote_details' => '{}', 'priority' => 'normal', 'revision' => 1,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function line(string $name, string $draft, int $order): array
    {
        return array_merge(array_fill_keys($this->source->columns['rfq_draft_rows'], null), [
            'id' => $this->id($name), 'draft_id' => $this->id($draft), 'row_order' => $order, 'row_data' => "{\"raw_text\": \"{$name}\"}", 'system_snapshot_valid' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function event(string $name, string $draft, string $type): array
    {
        return array_merge(array_fill_keys($this->source->columns['rfq_draft_activity'], null), [
            'id' => $this->id($name), 'draft_id' => $this->id($draft), 'created_at' => '2026-10-02T00:00:00+00:00', 'event_type' => $type,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function both(string $table, array $row): void
    {
        $this->source->put($table, LegacyRfqTables::TABLES[$table], $row);
        $this->insert($table, $row);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->pdo->prepare("INSERT INTO [rfq].[{$table}] ([" . implode('], [', $columns) . ']) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($row));
    }

    private function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    private function label(string $draft): ?string
    {
        return $this->texts("SELECT [label] FROM [rfq].[rfq_draft_quotes] WHERE [id] = '{$this->id($draft)}'")[0] ?? null;
    }

    /** @return list<string> line names, by order */
    private function lines(string $draft): array
    {
        $names = [];
        foreach ($this->texts("SELECT [row_data] FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = '{$this->id($draft)}' ORDER BY [row_order]") as $raw) {
            $data = json_decode($raw, true);
            $names[] = is_array($data) ? LegacyRfqRowHash::text($data['raw_text'] ?? '') : '';
        }

        return $names;
    }

    /** @return list<string> */
    private function events(string $draft): array
    {
        $names = array_map(
            static fn (string $id): string => (string) hex2bin(rtrim($id, '0') . (strlen(rtrim($id, '0')) % 2 ? '0' : '')),
            $this->texts("SELECT [id] FROM [rfq].[rfq_draft_activity] WHERE [draft_id] = '{$this->id($draft)}'"),
        );
        sort($names);

        return $names;
    }

    /** @return list<string> the first column of every row */
    private function texts(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return array_map(static fn (mixed $v): string => LegacyRfqRowHash::text($v), array_values($statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}
