<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Storage;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\DraftChangeLog;
use TriCab\Core\Rfq\Storage\DraftRowSet;
use TriCab\Core\Rfq\Storage\PythonJson;
use TriCab\Core\Rfq\Storage\PythonText;
use TriCab\Core\Tests\Rfq\Fixtures\DraftStoreGolden;

/**
 * The PHP port against the source store's own output.
 *
 * `Fixtures/draft-store-golden.json` is what the source `DraftStore` wrote,
 * with its clock and uuid4 frozen (`Fixtures/generate_draft_store_golden.py`).
 * Every comparison here is exact: bytes of JSON, edit values, ids and order.
 * `AzureSqlRfqDraftStoreTest` repeats the scenarios through SQL Server.
 */
final class DraftStoreParityTest extends TestCase
{
    private int $drawn = 0;

    public function testEditTextMatchesPythonForEveryCase(): void
    {
        $cases = DraftStoreGolden::editTextCases();
        self::assertGreaterThanOrEqual(40, count($cases));
        foreach ($cases as $case) {
            self::assertSame($case['out'], PythonText::editText(PythonJson::decode($case['in_json'])), 'input ' . $case['in_json']);
        }
    }

    public function testTrackedValuesAndLineSummaryMatchPython(): void
    {
        foreach (DraftStoreGolden::trackedCases() as $i => $case) {
            self::assertSame($case['values'], DraftChangeLog::trackedValues($case['row']), "row {$i}");
            self::assertSame($case['summary'], DraftChangeLog::lineSummary($case['row']), "row {$i}");
        }
    }

    public function testJsonRoundTripsPythonsOwnBytes(): void
    {
        foreach (DraftStoreGolden::jsonDumps() as $text) {
            self::assertSame($text, PythonJson::encode(PythonJson::decode($text)));
        }
        // NaN is in 18 live snapshots: it must read and write back unchanged.
        $nan = DraftStoreGolden::jsonDumpsNan();
        self::assertStringContainsString('NaN', $nan);
        self::assertSame($nan, PythonJson::encode(PythonJson::decode($nan)));
    }

    public function testCreateWritesTheSameRowsAsTheSource(): void
    {
        $state = DraftStoreGolden::tables('create');
        $draftId = (string) $state['draft']['id'];

        // save_draft draws the draft id first, then the row set draws.
        $result = DraftRowSet::replace($draftId, [], DraftStoreGolden::createRows(), true, null, null, '', $this->ids(1), self::now());

        self::assertSame($state['rows'], array_map(DraftStoreGolden::textRow(...), $result['rows']));
        self::assertSame([], $result['edits']);
        // The draft id, the row set's, and the activity id.
        self::assertSame(DraftStoreGolden::int('create', 'ids_used'), 1 + $this->drawn + 1);
    }

    public function testEditSaveMatchesTheSourceIdsIncluded(): void
    {
        $create = DraftStoreGolden::tables('create');
        $state = DraftStoreGolden::tables('edit');

        $result = DraftRowSet::replace(
            (string) $create['draft']['id'],
            self::previousOf($create['rows']),
            DraftStoreGolden::lines('edit', 'input_rows'),
            false,
            new DraftActor('rep@tricab.com', 'sales', 'VIC'),
            2,
            'simple',
            $this->ids(DraftStoreGolden::int('edit', 'ids_start')),
            self::now(),
        );

        self::assertSame($state['rows'], array_map(DraftStoreGolden::textRow(...), $result['rows']));
        self::assertSame($state['edits'], array_map(DraftStoreGolden::textRow(...), $result['edits']));

        $activity = end($state['activity']);
        self::assertIsArray($activity);
        $detail = PythonJson::decodeObject($activity['detail'] ?? null);
        self::assertSame(
            $detail['header_changes'] ?? null,
            DraftChangeLog::headerChanges(PythonJson::decodeObject($create['draft']['quote_details'] ?? null), DraftStoreGolden::object('edit', 'input_header')),
        );
    }

    public function testLegacyDraftIsMatchedByPositionOnce(): void
    {
        $before = DraftStoreGolden::tables('legacy_positional', 'before');
        $state = DraftStoreGolden::tables('legacy_positional');

        $result = DraftRowSet::replace(
            (string) $before['draft']['id'],
            self::previousOf($before['rows']),
            DraftStoreGolden::lines('legacy_positional', 'input_rows'),
            false,
            null,
            2,
            '',
            $this->ids(DraftStoreGolden::int('legacy_positional', 'ids_start')),
            self::now(),
        );

        self::assertSame($state['rows'], array_map(DraftStoreGolden::textRow(...), $result['rows']));
        self::assertSame($state['edits'], array_map(DraftStoreGolden::textRow(...), $result['edits']));
    }

    /** @return callable(): string */
    private function ids(int $start): callable
    {
        $this->drawn = 0;
        $n = $start;

        return function () use (&$n): string {
            $this->drawn++;

            return sprintf('%032x', ++$n);
        };
    }

    /** @return callable(): string */
    private static function now(): callable
    {
        $frozen = DraftStoreGolden::frozenNow();

        return static fn (): string => $frozen;
    }

    /**
     * @param list<array<string, ?string>> $rows
     *
     * @return list<array{line_uid: ?string, row_data: ?string, system_row_data: ?string, system_snapshot_valid: ?string}>
     */
    private static function previousOf(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'line_uid' => $r['line_uid'] ?? null,
            'row_data' => $r['row_data'] ?? null,
            'system_row_data' => $r['system_row_data'] ?? null,
            'system_snapshot_valid' => $r['system_snapshot_valid'] ?? null,
        ], $rows);
    }
}
