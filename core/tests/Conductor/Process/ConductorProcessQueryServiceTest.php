<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Conductor\Process;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Conductor\Process\Persistence\InMemoryConductorProcessRepository;
use TriCab\Api\Tests\Conductor\Process\FixtureData;
use TriCab\Core\Conductor\Module;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessInvalidRequest;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessItemNotFound;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessMachineNotFound;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessStoreUnavailable;
use TriCab\Core\Conductor\Process\Application\Service\ConductorProcessQueryService;

/**
 * The shaping rules of docs/conductor/CONTRACT.md section 5, against the in-memory repository and FixtureData.
 */
final class ConductorProcessQueryServiceTest extends TestCase
{
    public function test_module_declares_its_one_permission(): void
    {
        self::assertSame('Conductor', Module::NAME);
        self::assertSame('CONDUCTOR.READ', Module::PERMISSION_READ);
        self::assertSame(['CONDUCTOR.READ'], Module::PERMISSIONS);
    }

    public function test_run_counts_and_stages(): void
    {
        $data = self::service()->run();

        self::assertSame(
            ['builtAt' => '2026-10-28T01:02:03Z', 'dataAsOf' => '2026-10-27', 'windowFrom' => '2025-10-28', 'sourceName' => 'snapshot-2026-10-28', 'schemaVersion' => 1, 'engineVersion' => '1.0.0'],
            $data['run'],
        );
        self::assertSame(['items' => 9, 'finishedItems' => 4, 'tracedToRod' => 8, 'excludedOffMap' => 2], $data['counts']);
        self::assertSame(
            [['stage' => 'wire', 'items' => 4], ['stage' => 'bunch', 'items' => 1], ['stage' => 'conductor', 'items' => 3], ['stage' => 'mica_taped', 'items' => 1]],
            $data['stages'],
        );
    }

    public function test_run_without_a_run_row_is_null_with_zero_counts(): void
    {
        $data = (new ConductorProcessQueryService(new InMemoryConductorProcessRepository()))->run();

        self::assertNull($data['run']);
        self::assertSame(['items' => 0, 'finishedItems' => 0, 'tracedToRod' => 0, 'excludedOffMap' => 0], $data['counts']);
        self::assertSame([0, 0, 0, 0], array_column(self::rows($data['stages']), 'items'));
    }

    public function test_machines_in_seq_order_with_cancelled_share(): void
    {
        $machines = self::rows(self::service()->machines()['machines']);

        self::assertSame(['RB2', 'WD3', 'WD4', 'TN1', 'WD5', 'BU1', 'BU2', 'BU6', 'BU7', 'BU5', 'BU4', 'TP3', 'TP1', 'TP2', 'ST1'], array_column($machines, 'machine'));
        self::assertSame(range(1, 15), array_column($machines, 'seq'));
        self::assertEqualsWithDelta(10 / 11, $machines[8]['cancelledShare'], 1e-12);
        self::assertSame(0.0, $machines[1]['cancelledShare']);
        self::assertNull($machines[11]['cancelledShare'], 'TP3 has no orders');
        self::assertNull($machines[11]['d365Id']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function machineNames(): iterable
    {
        yield 'poster name' => ['BU6', 'BU6'];
        yield 'lower case' => ['bu6', 'BU6'];
        yield 'D365 RB1 is RB2' => ['RB1', 'RB2'];
        yield 'D365 tl1 is TN1' => ['tl1', 'TN1'];
        yield 'poster TN1' => ['TN1', 'TN1'];
        yield 'padded' => ['  TP3 ', 'TP3'];
    }

    #[DataProvider('machineNames')]
    public function test_machine_lookup(string $asked, string $found): void
    {
        $machine = self::service()->machine($asked)['machine'];

        self::assertIsArray($machine);
        self::assertSame($found, $machine['machine']);
    }

    public function test_machine_operations_sorted_by_orders_then_name(): void
    {
        self::assertSame(
            [['operation' => 'ANNEAL', 'orders' => 56], ['operation' => 'TINNING', 'orders' => 56], ['operation' => 'WIRE_DRAW', 'orders' => 2]],
            self::service()->machine('TN1')['operations'],
        );
    }

    public function test_machine_items_representative_and_alternative(): void
    {
        $service = self::service();

        // BU2: DX-1C2.5 runs there; NX-1C16 has run there 11 times as an alternative.
        self::assertSame(
            [
                ['item' => 'DX-1C2.5', 'stage' => 'conductor', 'material' => 'tinned', 'size' => 2.5, 'form' => 'core', 'role' => 'representative', 'orders' => 80],
                ['item' => 'NX-1C16', 'stage' => 'conductor', 'material' => 'plain', 'size' => 16.0, 'form' => 'core', 'role' => 'alternative', 'orders' => 11],
            ],
            $service->machine('BU2')['items'],
        );

        // WD5: DX-1W.5 is representative (its own alt row for WD5 does not make it an alternative too).
        self::assertSame(
            [['DX-1W.5', 'representative', 58], ['NX-16W.3', 'alternative', 7]],
            array_map(static fn (array $r): array => [$r['item'], $r['role'], $r['orders']], self::rows($service->machine('WD5')['items'])),
        );

        // BU7: only an alternative; stage then item order across stages.
        self::assertSame(['NX-32W.3'], array_column(self::rows($service->machine('BU7')['items']), 'item'));
        self::assertSame([], $service->machine('TP3')['items']);
    }

    public function test_machine_items_are_capped_at_500(): void
    {
        $items = [];
        $operations = [];
        for ($i = 0; $i < 510; $i++) {
            $name = sprintf('NX-%03dW.3', $i);
            $items[] = ['item' => $name, 'pool' => '15_WIR', 'stage' => 'wire', 'prefix' => 'NX', 'material' => 'plain', 'mica' => false, 'form' => 'wires', 'ends' => $i, 'size' => 0.3, 'diameterMm' => null, 'kgPerM' => null, 'unit' => 'm', 'orderNo' => 'W1', 'orderStatus' => 'Released', 'orderDate' => '2026-10-01', 'realOrders' => 1, 'liveOrders' => 1, 'orders52w' => 1, 'runSize' => null, 'tracesToRod' => false, 'stepCount' => 1];
            $operations[] = ['item' => $name, 'seq' => 1, 'operationNumber' => 10, 'operation' => 'WIRE_DRAW', 'machine' => 'WD4'];
        }
        $service = new ConductorProcessQueryService(new InMemoryConductorProcessRepository(machines: FixtureData::machines(), items: $items, operations: $operations));

        $rows = self::rows($service->machine('WD4')['items']);
        self::assertCount(500, $rows);
        self::assertSame('NX-000W.3', $rows[0]['item']);
        self::assertSame('NX-499W.3', $rows[499]['item']);
    }

    #[DataProvider('blank')]
    public function test_machine_is_required(?string $value): void
    {
        try {
            self::service()->machine($value);
            self::fail('Expected ConductorProcessInvalidRequest.');
        } catch (ConductorProcessInvalidRequest $e) {
            self::assertSame('CONDUCTOR_MACHINE_REQUIRED', $e->errorCode);
        }
    }

    public function test_unknown_machine(): void
    {
        $this->expectException(ConductorProcessMachineNotFound::class);
        self::service()->machine('EX6');
    }

    public function test_items_default_order_and_paging(): void
    {
        $data = self::service()->items(null, null, null, null, null);

        // Stage order, then size with nulls last, then item.
        self::assertSame(
            ['NX-16W.3', 'DX-1W.5', 'NX-1W1.6', 'NX-WEIRD', 'NX-32W.3', 'DX-1C2.5', 'NX-1C16', 'VX-1C35', 'NM-1C16'],
            array_column(self::rows($data['items']), 'item'),
        );
        self::assertSame([1, 50, 9, 1], [$data['page'], $data['pageSize'], $data['totalItems'], $data['totalPages']]);

        $page = self::service()->items(null, null, null, '2', '4');
        self::assertSame(['NX-32W.3', 'DX-1C2.5', 'NX-1C16', 'VX-1C35'], array_column(self::rows($page['items']), 'item'));
        self::assertSame(3, $page['totalPages']);

        self::assertSame([], self::service()->items(null, null, null, '9', null)['items']);
    }

    public function test_items_filters(): void
    {
        $service = self::service();

        self::assertSame(['DX-1C2.5', 'NX-1C16', 'VX-1C35', 'NM-1C16'], array_column(self::rows($service->items(null, 'Finished', null, null, null)['items']), 'item'));
        self::assertSame(['NX-32W.3'], array_column(self::rows($service->items(null, 'bunch', null, null, null)['items']), 'item'));
        self::assertSame(['DX-1W.5', 'DX-1C2.5'], array_column(self::rows($service->items(null, null, 'TINNED', null, null)['items']), 'item'));
        self::assertSame(['VX-1C35'], array_column(self::rows($service->items(null, null, 'unknown', null, null)['items']), 'item'));
        self::assertSame(['NX-1C16', 'NM-1C16'], array_column(self::rows($service->items('1c16', null, null, null, null)['items']), 'item'));
        self::assertSame(['NX-1C16'], array_column(self::rows($service->items(' 1c16 ', 'conductor', 'plain', null, null)['items']), 'item'));
        self::assertSame(0, $service->items('%', null, null, null, null)['totalItems']);
        self::assertSame(1, $service->items('', null, null, null, null)['totalPages']);
    }

    public function test_item_row_shape(): void
    {
        $row = self::rows(self::service()->items('NM-1C16', null, null, null, null)['items'])[0];

        self::assertSame(
            ['item' => 'NM-1C16', 'pool' => '30_TAPE', 'stage' => 'mica_taped', 'prefix' => 'NM', 'material' => 'plain', 'mica' => true, 'form' => 'core', 'ends' => 1, 'size' => 16.0, 'kgPerM' => 0.15, 'unit' => 'm', 'orders52w' => 20, 'runSize' => 1000.0, 'tracesToRod' => true],
            $row,
        );
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, ?string}>
     */
    public static function badItemsParameters(): iterable
    {
        yield 'unknown stage' => ['cable', null, null, null];
        yield 'unknown material' => [null, 'aluminium', null, null];
        yield 'page zero' => [null, null, '0', null];
        yield 'page text' => [null, null, 'abc', null];
        yield 'page decimal' => [null, null, '1.5', null];
        yield 'pageSize zero' => [null, null, null, '0'];
        yield 'pageSize over max' => [null, null, null, '201'];
        yield 'pageSize negative' => [null, null, null, '-5'];
    }

    #[DataProvider('badItemsParameters')]
    public function test_items_rejects_bad_parameters(?string $stage, ?string $material, ?string $page, ?string $pageSize): void
    {
        try {
            self::service()->items(null, $stage, $material, $page, $pageSize);
            self::fail('Expected ConductorProcessInvalidRequest.');
        } catch (ConductorProcessInvalidRequest $e) {
            self::assertSame('CONDUCTOR_PARAM_INVALID', $e->errorCode);
        }
    }

    public function test_items_accepts_the_largest_page_size(): void
    {
        self::assertSame(200, self::service()->items(null, null, null, null, '200')['pageSize']);
    }

    public function test_item_chain_deepest_first_with_quantities_for_the_length(): void
    {
        $data = self::service()->item('nm-1c16', '2000');

        self::assertSame(2000, $data['length']);
        $item = $data['item'];
        self::assertIsArray($item);
        self::assertSame('NM-1C16', $item['item']);
        self::assertSame(5, $item['stepCount']);
        self::assertSame(['W0049508', 'Released', 30, 25], [$item['orderNo'], $item['orderStatus'], $item['realOrders'], $item['liveOrders']]);

        $steps = self::rows($data['steps']);
        self::assertSame(['NX-1W1.6', 'NX-16W.3', 'NX-32W.3', 'NX-1C16', 'NM-1C16'], array_column($steps, 'item'));
        self::assertSame([1, 2, 3, 4, 5], array_column($steps, 'stepNo'));
        self::assertSame([4, 3, 2, 1, 0], array_column($steps, 'depth'));

        // NX-1W1.6: 2000 x 1 x 7.02611 x 2 x 0.5625 m, and kg from its kg/m.
        $wire = $steps[0];
        $metres = 2000 * 7.02611 * 2 * 0.5625;
        self::assertEqualsWithDelta($metres, $wire['quantity'], 1e-6);
        self::assertEqualsWithDelta($metres * 0.01804, $wire['kg'], 1e-6);
        $inputs = self::rows($wire['inputs']);
        self::assertSame('NX-1R8-NEX', $inputs[0]['item']);
        self::assertEqualsWithDelta($metres * 0.01804, $inputs[0]['quantity'], 1e-6);

        // No kg/m, no kg.
        self::assertNull($steps[1]['kg']);

        // Operations carry the D365 id and stage; alternatives drop the step's own machine, most orders first.
        self::assertSame(
            [['seq' => 1, 'operation' => 'WIRE_DRAW', 'machine' => 'WD4', 'd365Id' => 'WD4', 'stageNo' => 2, 'alternatives' => [['machine' => 'WD5', 'orders' => 7], ['machine' => 'WD3', 'orders' => 3]]]],
            $steps[1]['operations'],
        );
        self::assertSame('RB1', self::rows($wire['operations'])[0]['d365Id']);

        // The root's inputs in line order: the made conductor, then the mica tape.
        $root = self::rows($steps[4]['inputs']);
        self::assertSame(
            [
                ['item' => 'NX-1C16', 'kind' => 'made', 'qtyPerUnit' => 1.0, 'unit' => 'm', 'parallelEnds' => 1, 'quantity' => 2000.0],
                ['item' => 'MICA_TAPE', 'kind' => 'material', 'qtyPerUnit' => 0.005, 'unit' => 'kg', 'parallelEnds' => 1, 'quantity' => 10.0],
            ],
            $root,
        );
        self::assertSame(300.0, $steps[4]['kg']);

        // Materials: rod first, then by item; quantities for the whole length.
        $materials = self::rows($data['materials']);
        self::assertSame(['NX-1R8-NEX', 'MICA_TAPE'], array_column($materials, 'item'));
        self::assertTrue($materials[0]['isRod']);
        self::assertSame('kg', $materials[0]['unit']);
        self::assertEqualsWithDelta($metres * 0.01804, $materials[0]['quantity'], 1e-6);
        self::assertSame(['item' => 'MICA_TAPE', 'name' => null, 'unit' => 'kg', 'isRod' => false, 'quantity' => 10.0], $materials[1]);

        self::assertSame([], $data['usedBy']);
    }

    public function test_item_two_operations_and_used_by(): void
    {
        $service = self::service();

        $tinned = self::rows($service->item('DX-1W.5', null)['steps']);
        self::assertSame(['NX-1W1.6', 'DX-1W.5'], array_column($tinned, 'item'));
        self::assertSame(['WD5', 'TN1'], array_column(self::rows($tinned[1]['operations']), 'machine'));
        self::assertSame(['WD5', 'TL1'], array_column(self::rows($tinned[1]['operations']), 'd365Id'));

        $wire = $service->item('NX-1W1.6', null);
        self::assertSame(1000, $wire['length']);
        self::assertSame(
            [
                ['item' => 'DX-1W.5', 'stage' => 'wire', 'qtyPerUnit' => 0.1, 'unit' => 'm'],
                ['item' => 'NX-16W.3', 'stage' => 'wire', 'qtyPerUnit' => 0.5625, 'unit' => 'm'],
                ['item' => 'NX-WEIRD', 'stage' => 'wire', 'qtyPerUnit' => 1.0, 'unit' => 'm'],
            ],
            $wire['usedBy'],
        );
    }

    public function test_item_not_traced_to_rod(): void
    {
        $data = self::service()->item('VX-1C35', '0.5');

        self::assertSame(0.5, $data['length']);
        self::assertIsArray($data['item']);
        self::assertFalse($data['item']['tracesToRod']);
        self::assertSame(['VX-1C35'], array_column(self::rows($data['steps']), 'item'));
        self::assertSame([['item' => 'VX-WIRE', 'name' => 'Purchased wire', 'unit' => 'm', 'isRod' => false, 'quantity' => 9.75]], $data['materials']);
    }

    #[DataProvider('blank')]
    public function test_item_is_required(?string $value): void
    {
        try {
            self::service()->item($value, null);
            self::fail('Expected ConductorProcessInvalidRequest.');
        } catch (ConductorProcessInvalidRequest $e) {
            self::assertSame('CONDUCTOR_ITEM_REQUIRED', $e->errorCode);
        }
    }

    public function test_unknown_item(): void
    {
        $this->expectException(ConductorProcessItemNotFound::class);
        self::service()->item('NX-1C17', null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLengths(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero decimal' => ['0.0'];
        yield 'negative' => ['-1'];
        yield 'text' => ['long'];
        yield 'exponent' => ['1e3'];
        yield 'over max' => ['10000001'];
        yield 'way over max' => ['99999999999'];
    }

    #[DataProvider('badLengths')]
    public function test_item_rejects_a_bad_length(string $length): void
    {
        try {
            self::service()->item('NX-1C16', $length);
            self::fail('Expected ConductorProcessInvalidRequest.');
        } catch (ConductorProcessInvalidRequest $e) {
            self::assertSame('CONDUCTOR_PARAM_INVALID', $e->errorCode);
        }
    }

    public function test_item_accepts_the_largest_length(): void
    {
        self::assertSame(10_000_000, self::service()->item('NX-1C16', '10000000')['length']);
        self::assertSame(12.5, self::service()->item('NX-1C16', '12.50')['length']);
    }

    public function test_store_unavailable_passes_through(): void
    {
        $this->expectException(ConductorProcessStoreUnavailable::class);
        (new ConductorProcessQueryService(new InMemoryConductorProcessRepository(available: false)))->machines();
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function blank(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
    }

    private static function service(): ConductorProcessQueryService
    {
        return new ConductorProcessQueryService(FixtureData::inMemory());
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
