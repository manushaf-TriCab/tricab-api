<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Conductor\Schedule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use TriCab\Api\Conductor\Schedule\Persistence\InMemoryConductorScheduleRepository;
use TriCab\Api\Tests\Conductor\Schedule\FixtureData;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleBatchNotFound;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleInvalidRequest;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleOpNotFound;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleStoreUnavailable;
use TriCab\Core\Conductor\Schedule\Application\Service\ConductorScheduleQueryService;

/**
 * The shaping rules of docs/conductor/SCHEDULE-CONTRACT.md section 6, against the in-memory repository and FixtureData.
 */
final class ConductorScheduleQueryServiceTest extends TestCase
{
    public function test_run_shape(): void
    {
        $data = self::service()->run();

        self::assertSame(
            [
                'builtAt' => '2026-10-28T09:12:44Z', 'engineVersion' => '1.0.0', 'sourceName' => 'cs-source-2026-10-28', 'today' => '2026-10-28',
                'horizonEnd' => '2026-11-22', 'dataAsOf' => '2026-10-28', 'demand' => 'r86-order', 'rateWindow' => '13w',
                'settingsHash' => '9f2c1a7be03d4a11', 'settingsSource' => 'seed', 'schemaVersion' => 1, 'inventoryFile' => 'inventory.csv',
                'inventoryExported' => '2026-10-26',
            ],
            $data['run'],
        );
        self::assertSame(
            [
                'order' => ['file' => 'R-86_ORD_MTD_2610', 'from' => '2026-10-01', 'printed' => '2026-10-27T20:01:57', 'weeks' => 3.714, 'unplacedWeeklyM' => 6004.5],
                'invoice' => ['file' => 'R-86_INV_MTD_2610', 'from' => '2026-10-01', 'printed' => '2026-10-27T20:02:31', 'weeks' => 3.714, 'unplacedWeeklyM' => null],
                'shortPeriod' => true,
            ],
            $data['r86'],
        );
        self::assertSame(
            [
                'restockItems' => 3, 'releaseItems' => 1, 'releaseRuns' => 4, 'releaseM' => 44960.0, 'releaseKg' => 6402.304, 'jobs' => 4,
                'jobsScheduled' => 3, 'ops' => 3, 'passes' => 5, 'batches' => 2, 'lateOps' => 1, 'issuesWarning' => 3, 'issuesInfo' => 3,
            ],
            $data['counts'],
        );
        self::assertSame(['headers', 'inventory', 'r86-order'], array_column(self::rows($data['sources']), 'role'));
        self::assertSame(['name' => 'inventory.csv', 'role' => 'inventory', 'asOf' => '2026-10-26', 'rows' => 5626], self::rows($data['sources'])[1]);
        self::assertNull(self::rows($data['sources'])[2]['rows']);
    }

    public function test_run_settings_are_camel_cased_at_the_top_level_only(): void
    {
        $settings = self::service()->run()['settings'];

        self::assertIsArray($settings);
        self::assertSame(
            ['batchSizes', 'demand', 'r86MinWeeks', 'rateWindow', 'refillWeeks', 'rodWarnWeeks', 'safetyWeeks', 'shifts', 'shutdown', 'yields'],
            array_keys($settings),
        );
        self::assertSame(3, $settings['refillWeeks']);
        self::assertSame('13w', $settings['rateWindow']);
        self::assertInstanceOf(stdClass::class, $settings['shifts'], 'an empty object stays an object');
        $yields = $settings['yields'];
        self::assertInstanceOf(stdClass::class, $yields);
        self::assertSame(1.0, $yields->rod_break, 'nested keys are unchanged');
        $shutdown = $settings['shutdown'];
        self::assertIsArray($shutdown);
        self::assertSame('2026-12-19', $shutdown[0]->from);
    }

    public function test_run_without_a_run_row_is_null_with_zero_counts(): void
    {
        $data = (new ConductorScheduleQueryService(new InMemoryConductorScheduleRepository()))->run();

        self::assertNull($data['run']);
        $counts = $data['counts'];
        self::assertIsArray($counts);
        self::assertSame(0, $counts['restockItems']);
        self::assertSame(0, $counts['issuesWarning']);
        self::assertInstanceOf(stdClass::class, $data['settings']);
    }

    public function test_restock_rows_are_sorted_release_first_then_by_item(): void
    {
        $data = self::service()->restock(null, null, null);

        self::assertSame(['NX-1C16', 'DX-1C2.5', 'NM-1C16'], array_column(self::rows($data['rows']), 'item'));
        self::assertSame(
            [
                'item' => 'NX-1C16', 'form' => 'conductor', 'material' => 'plain', 'mica' => false, 'csaMm2' => 16.0, 'kgPerM' => 0.1424,
                'weeklyUseM' => 23000.0, 'demandSource' => 'r86-order', 'unplaced' => false, 'issueWeeklyM' => 21000.0, 'kanbanM' => 11240.0,
                'kanbanAssumed' => false, 'runs52w' => 12, 'triggerM' => 92000.0, 'physicalM' => 50000.0, 'incomingM' => 0.0, 'positionM' => 50000.0,
                'weeksOnHand' => 2.1739, 'releaseRuns' => 4, 'releaseM' => 44960.0, 'releaseKg' => 6402.304, 'status' => 'release',
                'scheduled' => true, 'note' => null,
            ],
            self::rows($data['rows'])[0],
        );
        $tinned = self::rows($data['rows'])[1];
        self::assertSame('issues', $tinned['demandSource']);
        self::assertTrue($tinned['unplaced']);
        self::assertSame(
            ['items' => 3, 'releaseItems' => 1, 'releaseRuns' => 4, 'releaseM' => 44960.0, 'releaseKg' => 6402.304, 'okItems' => 2, 'noUseItems' => 0],
            $data['totals'],
        );
    }

    public function test_restock_by_size_rolls_up_mica_and_plain_with_the_r86_buckets(): void
    {
        $bySize = self::rows(self::service()->restock(null, null, null)['bySize']);

        self::assertCount(2, $bySize);
        self::assertSame(
            [
                'material' => 'plain', 'csaMm2' => 16.0, 'weeklyUseM' => 25000.0, 'issueWeeklyM' => 22900.0, 'r86OrderedWeeklyM' => 23000.0,
                'r86InvoicedWeeklyM' => 71500.0, 'triggerM' => 100000.0, 'physicalM' => 62000.0, 'incomingM' => 0.0, 'releaseM' => 44960.0,
            ],
            $bySize[0],
        );
        self::assertSame('tinned', $bySize[1]['material']);
        self::assertSame(4800.0, $bySize[1]['r86OrderedWeeklyM']);
        self::assertSame(0.0, $bySize[1]['r86InvoicedWeeklyM']);
    }

    public function test_restock_filters(): void
    {
        $service = self::service();

        $release = $service->restock('release', null, null);
        self::assertSame(['NX-1C16'], array_column(self::rows($release['rows']), 'item'));
        self::assertCount(2, self::rows($release['bySize']), 'status does not narrow the by-size roll-up');
        self::assertSame(1, self::totals($release)['items']);

        $tinned = $service->restock(null, 'tinned', null);
        self::assertSame(['DX-1C2.5'], array_column(self::rows($tinned['rows']), 'item'));
        self::assertSame(['tinned'], array_column(self::rows($tinned['bySize']), 'material'), 'material narrows the by-size roll-up');
        self::assertSame(['items' => 1, 'releaseItems' => 0, 'releaseRuns' => 0, 'releaseM' => 0.0, 'releaseKg' => 0.0, 'okItems' => 1, 'noUseItems' => 0], $tinned['totals']);

        self::assertSame(['NM-1C16'], array_column(self::rows($service->restock(null, null, 'false')['rows']), 'item'));
        self::assertSame(['NX-1C16', 'DX-1C2.5'], array_column(self::rows($service->restock(null, null, ' TRUE ')['rows']), 'item'));
        self::assertSame([], self::rows($service->restock('no_use', null, null)['rows']));
    }

    public function test_jobs_are_ordered_by_id_and_carry_their_op(): void
    {
        $data = self::service()->jobs(null, null, null, null);
        $jobs = self::rows($data['jobs']);

        self::assertSame([1, 2, 3, 4], array_column($jobs, 'jobId'));
        self::assertSame(
            [
                'jobId' => 1, 'item' => 'NX-1C16', 'runNo' => 1, 'metres' => 11240.0, 'dueDate' => '2026-11-12', 'status' => 'scheduled',
                'startAt' => '2026-10-28T06:00', 'endAt' => '2026-11-04T14:00', 'lateDays' => 0, 'rodKg' => 1600.5, 'outKg' => 1600.5,
                'passCount' => 2, 'stockM' => 0.0,
            ],
            $jobs[0],
        );
        self::assertSame(500.0, $jobs[2]['stockM']);
        self::assertSame(
            ['jobId' => 4, 'item' => 'NX-1C16', 'runNo' => 4, 'metres' => 11240.0, 'dueDate' => '2026-11-22', 'status' => 'no_rate',
                'startAt' => null, 'endAt' => null, 'lateDays' => null, 'rodKg' => null, 'outKg' => null, 'passCount' => 0, 'stockM' => 0.0],
            $jobs[3],
        );
        self::assertSame([1, 50, 4, 1], [$data['page'], $data['pageSize'], $data['totalJobs'], $data['totalPages']]);
    }

    public function test_jobs_filters_and_paging(): void
    {
        $service = self::service();

        self::assertSame([4], array_column(self::rows($service->jobs('no_rate', null, null, null)['jobs']), 'jobId'));
        self::assertSame([3], array_column(self::rows($service->jobs(null, 'true', null, null)['jobs']), 'jobId'));
        self::assertSame([1, 2, 4], array_column(self::rows($service->jobs(null, 'false', null, null)['jobs']), 'jobId'));

        $page = $service->jobs(null, null, '2', '3');
        self::assertSame([4], array_column(self::rows($page['jobs']), 'jobId'));
        self::assertSame([2, 3, 4, 2], [$page['page'], $page['pageSize'], $page['totalJobs'], $page['totalPages']]);

        $none = $service->jobs('from_stock', null, null, null);
        self::assertSame([], $none['jobs']);
        self::assertSame(1, $none['totalPages']);
    }

    public function test_ops_with_passes_and_implied_yield(): void
    {
        $data = self::service()->ops(null, null, null, null, null);
        $ops = self::rows($data['ops']);

        self::assertSame([1, 2, 3], array_column($ops, 'opId'));
        self::assertSame([3, 3, 1], [$data['totalOps'], $data['totalOps'], $data['totalPages']]);
        self::assertSame(1.0, $ops[0]['impliedYield']);
        self::assertEqualsWithDelta(1615.0 / 1700.0, $ops[1]['impliedYield'], 1e-12);
        self::assertNull($ops[2]['impliedYield']);
        self::assertSame(500.0, $ops[2]['stockMetres']);

        $passes = self::rows($ops[0]['passes']);
        self::assertSame([1, 2], array_column($passes, 'passId'));
        self::assertSame(
            [
                'passId' => 2, 'parentPassId' => 1, 'item' => 'NX-1W1.6', 'pool' => '15_WIR', 'depth' => 1, 'machine' => 'RB1', 'posterName' => 'RB2',
                'eligible' => ['RB1'], 'operation' => 'ROD_BREAK', 'quantity' => 300000.0, 'unit' => 'm', 'setupHours' => 0.75, 'runHours' => 12.4,
                'startAt' => '2026-10-28T06:30', 'endAt' => '2026-11-02T22:30', 'batchId' => 1, 'stockMetres' => 0.0,
            ],
            $passes[1],
        );
        self::assertNull($passes[0]['parentPassId']);
        self::assertSame(['BU1', 'BU2'], $passes[0]['eligible']);
    }

    public function test_ops_filters(): void
    {
        $service = self::service();
        $ids = static fn (array $data): array => array_column(self::rows($data['ops']), 'opId');

        self::assertSame([2], $ids($service->ops('bu2', null, null, null, null)));
        self::assertSame([1, 2], $ids($service->ops('RB1', null, null, null, null)));
        self::assertSame([3], $ids($service->ops(null, 'true', null, null, null)));
        self::assertSame([1, 2, 3], $ids($service->ops(null, null, ' nx-1c16 ', null, null)));
        self::assertSame([], $ids($service->ops(null, null, 'NX-1C25', null, null)));
        self::assertSame([2], $ids($service->ops(null, null, null, '2', '1')));
    }

    public function test_op_detail(): void
    {
        $op = self::obj(self::service()->op(' 2 ')['op']);

        self::assertSame(2, $op['opId']);
        self::assertSame([3, 4], array_column(self::rows($op['passes']), 'passId'));
        self::assertSame(2, $op['passCount']);
    }

    public function test_op_errors(): void
    {
        $service = self::service();

        self::assertThrows('CONDUCTOR_OP_REQUIRED', static fn () => $service->op(null));
        self::assertThrows('CONDUCTOR_OP_REQUIRED', static fn () => $service->op('   '));
        self::assertThrows('CONDUCTOR_PARAM_INVALID', static fn () => $service->op('abc'));
        self::assertThrows('CONDUCTOR_PARAM_INVALID', static fn () => $service->op('1.5'));
        self::assertThrows('CONDUCTOR_PARAM_INVALID', static fn () => $service->op('-1'));
        $this->expectException(ConductorScheduleOpNotFound::class);
        $service->op('99');
    }

    public function test_batches_list_without_draws(): void
    {
        $batches = self::rows(self::service()->batches()['batches']);

        self::assertSame([1, 2], array_column($batches, 'batchId'));
        self::assertSame(
            [
                'batchId' => 1, 'item' => 'NX-1W1.6', 'pool' => '15_WIR', 'machine' => 'RB1', 'posterName' => 'RB2', 'operation' => 'ROD_BREAK',
                'metres' => 825000.0, 'batchRule' => 'history', 'allocatedMetres' => 750000.0, 'surplusMetres' => 75000.0, 'setupHours' => 1.0,
                'runHours' => 30.5, 'startAt' => '2026-10-28T06:30', 'endAt' => '2026-11-02T22:30', 'opCount' => 2, 'reason' => 'shortage',
                'weightKg' => 825000.0 * 0.0178,
            ],
            $batches[0],
        );
        self::assertEqualsWithDelta(2730.0, $batches[1]['weightKg'], 1e-9);
        self::assertSame('TN1', $batches[1]['posterName']);
    }

    public function test_batch_detail_draws_and_feeders(): void
    {
        $service = self::service();

        $first = self::obj($service->batch('1')['batch']);
        self::assertSame(
            [
                ['consumerKind' => 'batch', 'consumerId' => 2, 'metres' => 150000.0, 'item' => 'DX-1W1.6'],
                ['consumerKind' => 'op', 'consumerId' => 1, 'metres' => 300000.0, 'item' => 'NX-1C16'],
                ['consumerKind' => 'op', 'consumerId' => 2, 'metres' => 300000.0, 'item' => 'NX-1C16'],
            ],
            $first['draws'],
        );
        self::assertSame([], $first['feeders']);

        $second = self::obj($service->batch('2')['batch']);
        self::assertSame([['consumerKind' => 'op', 'consumerId' => 3, 'metres' => 100000.0, 'item' => 'NX-1C16']], $second['draws']);
        self::assertSame([['batchId' => 1, 'item' => 'NX-1W1.6', 'metres' => 150000.0]], $second['feeders']);
    }

    public function test_batch_errors(): void
    {
        $service = self::service();

        self::assertThrows('CONDUCTOR_BATCH_REQUIRED', static fn () => $service->batch(null));
        self::assertThrows('CONDUCTOR_PARAM_INVALID', static fn () => $service->batch('x'));
        $this->expectException(ConductorScheduleBatchNotFound::class);
        $service->batch('7');
    }

    public function test_machine_load_defaults_to_four_weeks_from_monday(): void
    {
        $data = self::service()->machineLoad(null);
        $machines = self::rows($data['machines']);

        self::assertSame(4, $data['weeks']);
        self::assertSame('2026-10-26', $data['from']);
        self::assertSame(['RB1', 'TL1', 'WD3', 'WD4', 'WD5', 'BU1', 'BU2', 'BU4', 'BU5', 'BU6', 'BU7', 'ST1'], array_column($machines, 'machine'));

        $rb1 = $machines[0];
        self::assertSame(['machine' => 'RB1', 'posterName' => 'RB2', 'name' => 'Rod Breaker 1 (Niehoff)', 'calendarSource' => 'dynamics', 'efficiency' => 1.0, 'hoursPerWeek' => 64.0], array_slice($rb1, 0, 6));
        self::assertSame(
            [
                ['week' => '2026-10-26', 'availableHours' => 64.0, 'loadHours' => 40.0, 'utilisation' => 0.625],
                ['week' => '2026-11-02', 'availableHours' => 64.0, 'loadHours' => 16.0, 'utilisation' => 0.25],
                ['week' => '2026-11-09', 'availableHours' => 0.0, 'loadHours' => 0.0, 'utilisation' => null],
                ['week' => '2026-11-16', 'availableHours' => 64.0, 'loadHours' => 0.0, 'utilisation' => 0.0],
            ],
            $rb1['weeks'],
        );
        self::assertSame(192.0, $rb1['totalAvailableHours']);
        self::assertSame(56.0, $rb1['totalLoadHours']);
        self::assertEqualsWithDelta(56.0 / 192.0, $rb1['utilisation'], 1e-12);
        self::assertSame('override', $machines[2]['calendarSource']);
    }

    public function test_machine_load_week_choices(): void
    {
        $service = self::service();

        $eight = $service->machineLoad('8');
        self::assertCount(8, self::rows(self::rows($eight['machines'])[0]['weeks']));
        $rb1 = self::rows($eight['machines'])[0];
        $weeks = self::rows($rb1['weeks']);
        self::assertSame(8.0, $weeks[5]['loadHours'], 'the sixth week carries its load');
        self::assertNull($weeks[7]['utilisation'], 'past the stored weeks there is no capacity');

        self::assertCount(26, self::rows(self::rows($service->machineLoad('26')['machines'])[0]['weeks']));
        foreach (['5', '0', 'abc', '4.0', '-4'] as $bad) {
            self::assertThrows('CONDUCTOR_PARAM_INVALID', static fn () => $service->machineLoad($bad));
        }
    }

    public function test_machine_load_needs_a_run(): void
    {
        $this->expectException(ConductorScheduleStoreUnavailable::class);
        (new ConductorScheduleQueryService(new InMemoryConductorScheduleRepository()))->machineLoad(null);
    }

    public function test_stock_rod_and_sorted_rows(): void
    {
        $data = self::service()->stock();

        self::assertIsArray($data['rod']);
        self::assertSame('NX-1R8-NEX', $data['rod']['item']);
        self::assertSame(44709.91, $data['rod']['onHandKg']);
        self::assertSame(2612823.4, $data['rod']['incomingKg']);
        self::assertSame(70000.0, $data['rod']['weeklyUseKg']);
        self::assertEqualsWithDelta(44709.91 / 70000.0, $data['rod']['weeksCover'], 1e-12);
        self::assertSame(8.0, $data['rod']['warnWeeks']);
        self::assertTrue($data['rod']['low']);

        $rows = self::rows($data['rows']);
        self::assertSame(['NX-1W1.6', 'DX-1W1.6', 'NX-1C16'], array_column($rows, 'item'), 'by point: wire_plain, wire_tinned, conductor; the rod is not a row');
        self::assertSame(
            [
                'item' => 'NX-1W1.6', 'point' => 'wire_plain', 'pool' => '15_WIR', 'unit' => 'm', 'onHandM' => 307590.73, 'usedM' => 0.0,
                'onHandKg' => 5474.9, 'usedKg' => 0.0, 'incomingM' => 144835000.0, 'kgPerM' => 0.0178,
            ],
            $rows[0],
        );
    }

    public function test_stock_without_a_rod_row(): void
    {
        $repository = new InMemoryConductorScheduleRepository(FixtureData::run(), stock: [FixtureData::stock()[0]]);
        $data = (new ConductorScheduleQueryService($repository))->stock();

        self::assertNull($data['rod']);
        self::assertCount(1, self::rows($data['rows']));
    }

    public function test_stock_weeks_cover_is_null_without_use(): void
    {
        $rod = FixtureData::stock()[2];
        $rod['weeklyUse'] = 0.0;
        $data = (new ConductorScheduleQueryService(new InMemoryConductorScheduleRepository(FixtureData::run(), stock: [$rod])))->stock();

        self::assertIsArray($data['rod']);
        self::assertNull($data['rod']['weeksCover']);
        self::assertFalse($data['rod']['low']);
    }

    public function test_issues_sorted_with_by_kind_roll_up(): void
    {
        $data = self::service()->issues(null, null);
        $issues = self::rows($data['issues']);

        self::assertSame(
            [['warning', 'no_rate', 'NX-1C16'], ['warning', 'r86_short_period', '(all)'], ['warning', 'rod_cover_low', '(all)'], ['info', 'mica_not_scheduled', 'NM-1C16'], ['info', 'rate_borrowed', 'DX-1C2.5'], ['info', 'rate_borrowed', 'NX-1C16']],
            array_map(static fn (array $i): array => [$i['severity'], $i['kind'], $i['item']], $issues),
        );
        self::assertSame(['item' => 'NX-1C16', 'kind' => 'no_rate', 'severity' => 'warning', 'detail' => 'no rate on any eligible machine', 'count' => 1], $issues[0]);
        self::assertSame(
            [
                ['kind' => 'no_rate', 'severity' => 'warning', 'items' => 1, 'count' => 1],
                ['kind' => 'r86_short_period', 'severity' => 'warning', 'items' => 1, 'count' => 1],
                ['kind' => 'rod_cover_low', 'severity' => 'warning', 'items' => 1, 'count' => 1],
                ['kind' => 'mica_not_scheduled', 'severity' => 'info', 'items' => 1, 'count' => 1],
                ['kind' => 'rate_borrowed', 'severity' => 'info', 'items' => 2, 'count' => 5],
            ],
            $data['byKind'],
        );
    }

    public function test_issues_filters(): void
    {
        $service = self::service();

        $info = $service->issues(null, 'info');
        self::assertCount(3, self::rows($info['issues']));
        self::assertCount(2, self::rows($info['byKind']));

        $kind = $service->issues('rate_borrowed', null);
        self::assertSame(['DX-1C2.5', 'NX-1C16'], array_column(self::rows($kind['issues']), 'item'));
        self::assertSame([], self::rows($service->issues('rate_borrowed', 'warning')['issues']));
    }

    /**
     * @return iterable<string, array{string, list<?string>}>
     */
    public static function invalidParams(): iterable
    {
        yield 'restock status' => ['restock', ['sleeping', null, null]];
        yield 'restock material' => ['restock', [null, 'brass', null]];
        yield 'restock scheduled' => ['restock', [null, null, 'yes']];
        yield 'jobs status' => ['jobs', ['done', null, null, null]];
        yield 'jobs late' => ['jobs', [null, 'maybe', null, null]];
        yield 'jobs page zero' => ['jobs', [null, null, '0', null]];
        yield 'jobs page text' => ['jobs', [null, null, 'x', null]];
        yield 'jobs page size zero' => ['jobs', [null, null, null, '0']];
        yield 'jobs page size too large' => ['jobs', [null, null, null, '201']];
        yield 'ops machine' => ['ops', ['ZZ9', null, null, null, null]];
        yield 'ops machine poster name' => ['ops', ['RB2', null, null, null, null]];
        yield 'ops late' => ['ops', [null, '1', null, null, null]];
        yield 'ops page' => ['ops', [null, null, null, '-1', null]];
        yield 'ops page size' => ['ops', [null, null, null, null, '1000']];
        yield 'issues kind' => ['issues', ['bogus', null]];
        yield 'issues severity' => ['issues', [null, 'error']];
    }

    /**
     * @param list<?string> $args
     */
    #[DataProvider('invalidParams')]
    public function test_invalid_params_are_param_invalid(string $method, array $args): void
    {
        $service = self::service();

        self::assertThrows('CONDUCTOR_PARAM_INVALID', static fn () => $service->{$method}(...$args));
    }

    public function test_page_size_200_is_allowed_and_blank_values_mean_not_given(): void
    {
        $service = self::service();

        self::assertSame(200, $service->jobs('', '  ', null, '200')['pageSize']);
        self::assertCount(4, self::rows($service->jobs('', '  ', null, null)['jobs']));
    }

    public function test_store_unavailable_passes_through(): void
    {
        $service = new ConductorScheduleQueryService(new InMemoryConductorScheduleRepository(available: false));

        $this->expectException(ConductorScheduleStoreUnavailable::class);
        $service->run();
    }

    private static function service(): ConductorScheduleQueryService
    {
        return new ConductorScheduleQueryService(FixtureData::inMemory());
    }

    /**
     * @param callable(): mixed $call
     */
    private static function assertThrows(string $code, callable $call): void
    {
        try {
            $call();
        } catch (ConductorScheduleInvalidRequest $e) {
            self::assertSame($code, $e->errorCode);

            return;
        }
        self::fail('Expected ConductorScheduleInvalidRequest ' . $code);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function totals(array $data): array
    {
        return self::obj($data['totals']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function obj(mixed $value): array
    {
        self::assertIsArray($value);
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $out[] = $row;
        }

        return $out;
    }
}
