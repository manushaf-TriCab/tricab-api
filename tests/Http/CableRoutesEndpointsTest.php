<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\CableRoutes\FixtureStore;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\CableRoutes\Module;
use TriCab\Core\Identity\Contract\UserRepository;

final class CableRoutesEndpointsTest extends TestCase
{
    private const BASE = '/api/v1/cable-routes/';

    protected function tearDown(): void
    {
        FixtureStore::cleanup();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpoints(): iterable
    {
        foreach (['run', 'codes', 'items', 'item?item=x', 'machines', 'machine?id=EX6', 'checks'] as $path) {
            yield $path => [$path];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function test_unauthenticated_is_rejected(string $path): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false', 'CABLE_ROUTES_DB_PATH' => FixtureStore::create()]);
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function test_without_the_permission_is_forbidden(string $path): void
    {
        $app = TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => FixtureStore::create()]);
        $this->replacePermissions($app, ['ACCOUNT.LOGIN']);
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_the_development_user_holds_the_permission(): void
    {
        $data = $this->get($this->app(), 'run');

        self::assertSame('2026-09-04', Payload::at($data, 'run', 'dataAsOf'));
    }

    public function test_run(): void
    {
        $data = $this->get($this->app(), 'run');

        self::assertSame(
            ['builtAt' => '2026-10-27T03:12:44Z', 'dataAsOf' => '2026-09-04', 'sourceName' => 'dynamics', 'schemaVersion' => 1, 'engineVersion' => '1.0.0'],
            $data['run'],
        );
        self::assertSame(5, Payload::at($data, 'counts', 'finishedItems'));
        self::assertSame(3, Payload::at($data, 'counts', 'tracedItems'));
        self::assertSame(15, Payload::at($data, 'counts', 'madeItems'));
        self::assertSame(1, Payload::at($data, 'counts', 'cycleEdgesCut'));
        self::assertSame(
            [
                ['status' => 'to_rod', 'count' => 1],
                ['status' => 'to_purchased_conductor', 'count' => 1],
                ['status' => 'stops_early', 'count' => 1],
                ['status' => 'untraceable', 'count' => 2],
            ],
            $data['traceStatus'],
        );
        self::assertSame(
            [['reason' => 'no_orders', 'count' => 1], ['reason' => 'cutting_only', 'count' => 1]],
            $data['untraceableReasons'],
        );
    }

    public function test_codes(): void
    {
        $data = $this->get($this->app(), 'codes');
        $codes = Payload::objList($data['codes']);

        self::assertSame(['KA', 'R2', 'ZZ'], array_column($codes, 'code'));
        $ka = $codes[0];
        self::assertSame('110 °C power cable with steel wire armour', $ka['description']);
        self::assertTrue($ka['inPoster']);
        self::assertSame(['Armoured cables', 'Flexible'], $ka['families']);
        self::assertSame(4, $ka['finishedItems']);
        self::assertSame(3, $ka['tracedItems']);
        $ranges = Payload::objList($ka['ranges']);
        self::assertSame(['Industrial', 'Marine'], array_column($ranges, 'range'));
        self::assertSame(
            ['range' => 'Industrial', 'description' => '110 °C power cable with steel wire armour', 'voltage' => '0.6/1kV', 'insulationJacket' => 'X-HF-110/PVC/SWA/PVC', 'conductor' => 'CU', 'maxTempC' => 110],
            $ranges[0],
        );
        self::assertFalse($codes[1]['inPoster']);
        self::assertNull($codes[1]['description']);
        self::assertSame([], $codes[1]['ranges']);
        // On the poster with no finished items: still listed.
        self::assertSame(0, $codes[2]['finishedItems']);
        self::assertSame(['fit' => 4, 'other' => 1], $data['parse']);
    }

    public function test_items_without_filters_lists_everything_in_item_order(): void
    {
        $data = $this->get($this->app(), 'items');

        self::assertSame(5, $data['totalItems']);
        self::assertSame(1, $data['page']);
        self::assertSame(50, $data['pageSize']);
        self::assertSame(1, $data['totalPages']);
        $items = Payload::objList($data['items']);
        self::assertSame(
            ['KA-STRANGE', 'KA9X-PASN/3C2.5', 'KA9X-PASN/5C16BK', 'KA9X-PASN/5C16RD', 'R2X-AAAA/3C1.5'],
            array_column($items, 'item'),
        );
        self::assertSame(
            ['item' => 'KA9X-PASN/5C16BK', 'code' => 'KA', 'construction' => '5C16', 'colour' => 'BK', 'diameterMm' => 27.85, 'kgPerM' => 1.678, 'unit' => 'm', 'pool' => '50_FMAN', 'traceStatus' => 'to_rod', 'reason' => null],
            $items[2],
        );
    }

    public function test_items_facets_ignore_the_construction_and_status_filters(): void
    {
        $data = $this->get($this->app(), 'items?code=ka&construction=5c16&traceStatus=to_rod');

        self::assertSame(1, $data['totalItems']);
        $facets = Payload::obj($data['facets']);
        self::assertSame(
            [['construction' => '3C2.5', 'count' => 1], ['construction' => '5C16', 'count' => 2], ['construction' => 'other', 'count' => 1]],
            $facets['constructions'],
        );
        self::assertSame(
            [['traceStatus' => 'to_rod', 'count' => 1], ['traceStatus' => 'to_purchased_conductor', 'count' => 1], ['traceStatus' => 'stops_early', 'count' => 1], ['traceStatus' => 'untraceable', 'count' => 1]],
            $facets['traceStatus'],
        );
    }

    public function test_items_other_construction_and_text_search(): void
    {
        $app = $this->app();

        $other = $this->get($app, 'items?construction=other');
        self::assertSame(['KA-STRANGE'], array_column(Payload::objList($other['items']), 'item'));

        $q = $this->get($app, 'items?q=' . rawurlencode('5c16'));
        self::assertSame(['KA9X-PASN/5C16BK', 'KA9X-PASN/5C16RD'], array_column(Payload::objList($q['items']), 'item'));

        // % and _ are literal, not wildcards.
        self::assertSame(0, $this->get($app, 'items?q=' . rawurlencode('%'))['totalItems']);
        self::assertSame(0, $this->get($app, 'items?q=' . rawurlencode('KA_'))['totalItems']);
    }

    public function test_items_paging(): void
    {
        $data = $this->get($this->app(), 'items?pageSize=2&page=2');

        self::assertSame(['KA9X-PASN/5C16BK', 'KA9X-PASN/5C16RD'], array_column(Payload::objList($data['items']), 'item'));
        self::assertSame(3, $data['totalPages']);
        self::assertSame(5, $data['totalItems']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badParameters(): iterable
    {
        yield 'page zero' => ['items?page=0'];
        yield 'page text' => ['items?page=abc'];
        yield 'pageSize zero' => ['items?pageSize=0'];
        yield 'pageSize too large' => ['items?pageSize=201'];
        yield 'unknown trace status' => ['items?traceStatus=nope'];
        yield 'checks pageSize too large' => ['checks?pageSize=501'];
        yield 'machine page zero' => ['machine?id=EX6&page=0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badParameters')]
    public function test_bad_parameter_is_422(string $path): void
    {
        [$status, $error] = $this->getError($this->app(), $path);

        self::assertSame(422, $status);
        self::assertSame('CABLE_ROUTES_PARAM_INVALID', $error['code']);
    }

    public function test_checks_accepts_a_large_page_size(): void
    {
        self::assertSame(500, $this->get($this->app(), 'checks?pageSize=500')['pageSize']);
    }

    public function test_item_the_worked_example(): void
    {
        $data = $this->get($this->app(), 'item?item=' . rawurlencode(FixtureStore::ITEM));

        self::assertSame('2026-09-04', $data['dataAsOf']);
        $item = Payload::obj($data['item']);
        self::assertSame('KA', $item['code']);
        self::assertSame('5C16', $item['construction']);
        self::assertSame('BK', $item['colour']);
        self::assertSame('to_rod', $item['traceStatus']);
        self::assertSame([], $item['reasonItems']);
        self::assertSame(10, $item['stepCount']);
        self::assertSame(8, $item['maxDepth']);

        $nodes = Payload::objList($data['nodes']);
        self::assertCount(15, $nodes);
        self::assertSame(FixtureStore::ITEM, $nodes[0]['item']);
        self::assertSame('finished', $nodes[0]['kind']);
        self::assertSame(0, $nodes[0]['depth']);
        $depths = array_column($nodes, 'depth');
        $sorted = $depths;
        sort($sorted);
        self::assertSame($sorted, $depths, 'nodes are sorted by depth');
        self::assertCount(1, array_filter($nodes, static fn (array $n): bool => $n['kind'] === 'finished'));

        $byItem = [];
        foreach ($nodes as $node) {
            $byItem[Payload::str($node['item'])] = $node;
        }
        $rod = $byItem['NX-1W1.6'];
        self::assertSame(8, $rod['depth']);
        self::assertSame('made', $rod['kind']);
        self::assertSame('15_WIR', $rod['pool']);
        self::assertSame(
            ['orderNo' => 'W0049517', 'status' => 'Released', 'date' => '2026-09-04', 'realOrders' => 473, 'liveOrders' => 473],
            $rod['order'],
        );
        self::assertSame(
            [['seq' => 1, 'operation' => 'ROD_BREAK', 'machine' => ['machine' => 'RB1', 'posterName' => 'RB2', 'stageNo' => 1, 'onPoster' => true, 'retired' => false]]],
            $rod['steps'],
        );
        self::assertSame(
            [['item' => 'NX-1R8-NEX', 'name' => 'Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe', 'qtyPerUnit' => 0.01804, 'unit' => 'kg']],
            $rod['materialInputs'],
        );
        self::assertSame(
            [['item' => 'RS0002', 'name' => null, 'qtyPerUnit' => 0.21541, 'unit' => 'kg'], ['item' => 'MB_BLACK', 'name' => 'Colour Masterbatch - BLACK', 'qtyPerUnit' => 0.0057, 'unit' => 'kg']],
            $nodes[0]['materialInputs'],
        );
        self::assertSame([['unit' => 'm', 'perUnit' => 4.0396]], $byItem['NX-1C16']['need']);

        // The step's own machine (BU1) is not an alternative; BU3 is.
        $bunch = Payload::objList($byItem['NX-1C16']['altMachines']);
        self::assertCount(1, $bunch);
        self::assertSame('BU3', Payload::obj($bunch[0]['machine'])['machine']);
        self::assertFalse(Payload::obj($bunch[0]['machine'])['onPoster']);
        self::assertSame(11, $bunch[0]['orders']);
        self::assertSame([], $byItem['NX-8W.3']['altMachines']);

        // 10 steps, deepest first: rod breaking, then up to the sheath.
        $steps = Payload::objList($data['steps']);
        self::assertCount(10, $steps);
        self::assertSame(range(1, 10), array_column($steps, 'stepNo'));
        self::assertSame(
            ['ROD_BREAK', 'WIRE_DRAW', 'BUNCHING', 'BUNCHING', 'BUNCHING', 'INSULATION', 'LAYUP', 'BEDDING', 'ARMOURING', 'SHEATHING'],
            array_column($steps, 'operation'),
        );
        self::assertSame(['EX6', 'LU9', 'EX3', 'LU3', 'EX4'], array_map(
            static fn (array $s): mixed => Payload::obj($s['machine'])['machine'],
            array_slice($steps, 5),
        ));
        self::assertSame(['NX-1W1.6'], $steps[0]['items']);
        self::assertSame(8, $steps[0]['maxDepth']);
        self::assertCount(5, Payload::list($steps[5]['items']));

        // Edges: the made-to-made links inside the tree, and none of the cut backward edge.
        $edges = Payload::objList($data['edges']);
        self::assertCount(18, $edges);
        self::assertSame([], array_filter($edges, static fn (array $e): bool => $e['parent'] === 'NX-1W1.6'));
        $ends = array_values(array_filter($edges, static fn (array $e): bool => $e['parent'] === 'NX-1C16'));
        self::assertSame([['parent' => 'NX-1C16', 'input' => 'NX-32W.3', 'qtyPerUnit' => 7.02611, 'unit' => 'm', 'parallelEnds' => 7]], $ends);

        // Materials: the rod first, then kg by quantity, then the metre-counted one.
        $materials = Payload::objList($data['materials']);
        self::assertSame(['NX-1R8-NEX', 'GSWA_1.60MM', 'RS0002', 'MB_BLACK', 'FILLER_PP'], array_column($materials, 'item'));
        self::assertTrue($materials[0]['isRod']);
        self::assertTrue($materials[0]['conductor']);
        self::assertSame(0.5755, $materials[0]['perUnit']);
        self::assertSame(9, $materials[0]['depth']);
        self::assertFalse($materials[1]['isRod']);

        // Checks that touch the tree, and the code-level row for KA; nothing for R2 or for machines alone.
        $checks = Payload::objList($data['checks']);
        self::assertSame(['routed_to_retired_machine', 'outside_poster_limit', 'stops_early'], array_column($checks, 'kind'));
    }

    public function test_item_untraceable_answers_with_an_empty_tree(): void
    {
        $data = $this->get($this->app(), 'item?item=' . rawurlencode('R2X-AAAA/3C1.5'));

        $item = Payload::obj($data['item']);
        self::assertSame('untraceable', $item['traceStatus']);
        self::assertSame('cutting_only', $item['reason']);
        $nodes = Payload::objList($data['nodes']);
        self::assertCount(1, $nodes);
        self::assertSame('R2X-AAAA/3C1.5', $nodes[0]['item']);
        self::assertNull($nodes[0]['order']);
        self::assertSame([], $nodes[0]['steps']);
        self::assertSame([], $data['edges']);
        self::assertSame([], $data['steps']);
        self::assertSame([], $data['materials']);
        self::assertSame(['family_route_mismatch', 'untraceable'], array_column(Payload::objList($data['checks']), 'kind'));
    }

    public function test_item_with_reason_items(): void
    {
        $data = $this->get($this->app(), 'item?item=' . rawurlencode('KA9X-PASN/3C2.5'));

        self::assertSame(['N1-1W2', 'N2-3W1'], Payload::obj($data['item'])['reasonItems']);
        self::assertSame('purchased_conductor', Payload::obj($data['item'])['reason']);
    }

    public function test_item_missing_is_422_and_unknown_is_404(): void
    {
        $app = $this->app();

        foreach (['item', 'item?item=', 'item?item=%20'] as $path) {
            [$status, $error] = $this->getError($app, $path);
            self::assertSame(422, $status);
            self::assertSame('CABLE_ROUTES_ITEM_REQUIRED', $error['code']);
        }

        [$status, $error] = $this->getError($app, 'item?item=NOPE%2F1C1');
        self::assertSame(404, $status);
        self::assertSame('CABLE_ROUTES_ITEM_NOT_FOUND', $error['code']);
    }

    public function test_machines_lists_in_stage_order_without_retired_ones(): void
    {
        $data = $this->get($this->app(), 'machines');
        $machines = Payload::objList($data['machines']);
        $names = array_column($machines, 'machine');

        self::assertSame(['RB1', 'WD4', 'BU1', 'BU2', 'BU6', 'EX6', 'LU9', 'EX3', 'EX4', 'LU3', 'BR2', 'CPD1'], $names);
        self::assertNotContains('EX8', $names);
        $rb1 = $machines[0];
        self::assertSame('RB2', $rb1['posterName']);
        self::assertSame('alias', $rb1['d365Status']);
        self::assertSame(1740, $rb1['speedMPerMin']);
        self::assertNull($rb1['limit']);
        $ex6 = $machines[5];
        self::assertSame(['minMm' => null, 'maxMm' => 10, 'note' => 'poster limit, 2023'], $ex6['limit']);
        self::assertSame(5, $ex6['madeItems']);
        $cpd1 = $machines[11];
        self::assertFalse($cpd1['onPoster']);
        self::assertNull($cpd1['stageNo']);
    }

    public function test_machine_by_id_and_by_poster_name(): void
    {
        $app = $this->app();

        $ex6 = $this->get($app, 'machine?id=ex6');
        $machine = Payload::obj($ex6['machine']);
        self::assertSame('EX6', $machine['machine']);
        self::assertSame('Cores to 10 mm', $machine['outputCapability']);
        self::assertSame('Drum', $machine['payOff']);
        self::assertSame('Cores are not printed.', $machine['otherNotes']);
        self::assertSame([['code' => 'KA', 'finishedItems' => 2]], $ex6['codes']);
        $items = Payload::obj($ex6['items']);
        self::assertSame(2, $items['totalItems']);
        self::assertSame(
            [['item' => 'KA9X-PASN/5C16BK', 'code' => 'KA', 'construction' => '5C16', 'colour' => 'BK', 'diameterMm' => 27.85, 'steps' => 5],
                ['item' => 'KA9X-PASN/5C16RD', 'code' => 'KA', 'construction' => '5C16', 'colour' => 'RD', 'diameterMm' => 27.85, 'steps' => 3]],
            $items['items'],
        );
        // EX6 is the machine of five made items; they all consume NX-1C16 or NX-1C6 (made) as their input.
        $top = Payload::objList($ex6['topInputs']);
        self::assertSame(['NX-1C16', 'NX-1C6'], array_column($top, 'item'));
        self::assertSame([4, 1], array_column($top, 'madeItems'));
        self::assertSame('made', $top[0]['kind']);
        self::assertSame('m', $top[0]['unit']);
        self::assertSame(['outside_poster_limit'], array_column(Payload::objList($ex6['checks']), 'kind'));

        // The map can link with the poster name: RB2 finds D365's RB1.
        $rb = $this->get($app, 'machine?id=RB2');
        self::assertSame('RB1', Payload::obj($rb['machine'])['machine']);
        self::assertSame('RB2', Payload::obj($rb['machine'])['posterName']);
        // NX-1W1.6 also lists the cut backward edge to NX-1C16 as an input row, so it comes first (same count, item order).
        $rbInputs = Payload::objList($rb['topInputs']);
        self::assertSame(['NX-1C16', 'NX-1R8-NEX'], array_column($rbInputs, 'item'));
        self::assertSame(
            ['item' => 'NX-1R8-NEX', 'name' => 'Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe', 'kind' => 'material', 'unit' => 'kg', 'madeItems' => 1],
            $rbInputs[1],
        );
    }

    public function test_machine_items_honour_code_and_paging(): void
    {
        $app = $this->app();

        $page = Payload::obj($this->get($app, 'machine?id=EX6&pageSize=1&page=2')['items']);
        self::assertSame(2, $page['totalPages']);
        self::assertSame(['KA9X-PASN/5C16RD'], array_column(Payload::objList($page['items']), 'item'));

        self::assertSame(0, Payload::obj($this->get($app, 'machine?id=EX6&code=R2')['items'])['totalItems']);
        self::assertSame(2, Payload::obj($this->get($app, 'machine?id=EX6&code=ka')['items'])['totalItems']);
    }

    public function test_machine_missing_hidden_and_unknown(): void
    {
        $app = $this->app();

        [$status, $error] = $this->getError($app, 'machine');
        self::assertSame(422, $status);
        self::assertSame('CABLE_ROUTES_MACHINE_REQUIRED', $error['code']);

        foreach (['EX8', 'NOPE'] as $id) {
            [$status, $error] = $this->getError($app, 'machine?id=' . $id);
            self::assertSame(404, $status, $id);
            self::assertSame('CABLE_ROUTES_MACHINE_NOT_FOUND', $error['code']);
        }
    }

    public function test_checks_counts_all_seven_kinds_in_order(): void
    {
        $data = $this->get($this->app(), 'checks');

        self::assertNull($data['kind']);
        self::assertSame(
            [
                ['kind' => 'family_route_mismatch', 'label' => 'Route differs from the poster family', 'count' => 1],
                ['kind' => 'machine_not_on_poster', 'label' => 'Machine in Dynamics but not on the poster', 'count' => 1],
                ['kind' => 'poster_machine_unused', 'label' => 'Poster machine with no Dynamics routings', 'count' => 1],
                ['kind' => 'routed_to_retired_machine', 'label' => 'Routed to a machine that is not used', 'count' => 1],
                ['kind' => 'outside_poster_limit', 'label' => 'Outside poster limit, 2023', 'count' => 1],
                ['kind' => 'stops_early', 'label' => 'Chain stops before copper rod or purchased conductor', 'count' => 1],
                ['kind' => 'untraceable', 'label' => 'No production orders to trace', 'count' => 1],
            ],
            $data['counts'],
        );
        self::assertSame(7, $data['totalItems']);
        self::assertSame(100, $data['pageSize']);
        $rows = Payload::objList($data['rows']);
        self::assertSame(
            ['family_route_mismatch', 'machine_not_on_poster', 'poster_machine_unused', 'routed_to_retired_machine', 'outside_poster_limit', 'stops_early', 'untraceable'],
            array_column($rows, 'kind'),
        );
        self::assertSame(
            ['checkId' => 1, 'kind' => 'family_route_mismatch', 'item' => null, 'code' => 'R2', 'machine' => null, 'operation' => 'ARMOURING', 'detail' => 'ARMOURING expected for R2; none in Dynamics', 'affected' => 18, 'total' => 18],
            $rows[0],
        );
    }

    public function test_checks_filters(): void
    {
        $app = $this->app();

        $kind = $this->get($app, 'checks?kind=untraceable');
        self::assertSame('untraceable', $kind['kind']);
        self::assertSame(1, $kind['totalItems']);
        self::assertCount(7, Payload::objList($kind['counts']), 'counts are not narrowed by kind');

        $code = $this->get($app, 'checks?code=r2');
        self::assertSame(2, $code['totalItems']);
        self::assertSame(1, Payload::objList($code['counts'])[0]['count']);
        self::assertSame(0, Payload::objList($code['counts'])[1]['count']);

        [$status, $error] = $this->getError($app, 'checks?kind=nope');
        self::assertSame(422, $status);
        self::assertSame('CABLE_ROUTES_KIND_INVALID', $error['code']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpointsThatNeedTheStore(): iterable
    {
        yield 'run' => ['run'];
        yield 'codes' => ['codes'];
        yield 'items' => ['items'];
        yield 'item' => ['item?item=x'];
        yield 'machines' => ['machines'];
        yield 'machine' => ['machine?id=EX6'];
        yield 'checks' => ['checks'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpointsThatNeedTheStore')]
    public function test_missing_store_is_503(string $path): void
    {
        $app = TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => FixtureStore::missingPath()]);

        [$status, $error] = $this->getError($app, $path);

        self::assertSame(503, $status);
        self::assertSame('CABLE_ROUTES_STORE_UNAVAILABLE', $error['code']);
        self::assertStringNotContainsString('/', Payload::str($error['message']), 'the message never contains a path');
    }

    public function test_a_validation_error_wins_over_a_missing_store(): void
    {
        $app = TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => FixtureStore::missingPath()]);

        [$status, $error] = $this->getError($app, 'item');

        self::assertSame(422, $status);
        self::assertSame('CABLE_ROUTES_ITEM_REQUIRED', $error['code']);
    }

    public function test_store_without_a_run_row_is_503(): void
    {
        $app = TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => FixtureStore::createWithoutRun()]);

        [$status, $error] = $this->getError($app, 'run');

        self::assertSame(503, $status);
        self::assertSame('CABLE_ROUTES_STORE_UNAVAILABLE', $error['code']);
    }

    public function test_store_with_another_schema_version_is_503(): void
    {
        $app = TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => FixtureStore::createWithVersion(2)]);

        [$status, $error] = $this->getError($app, 'run');

        self::assertSame(503, $status);
        self::assertSame('CABLE_ROUTES_STORE_UNAVAILABLE', $error['code']);
    }

    public function test_a_file_that_is_not_a_store_is_503(): void
    {
        $path = FixtureStore::missingPath();
        file_put_contents($path, 'this is not a sqlite database');
        try {
            $app = TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => $path]);
            [$status, $error] = $this->getError($app, 'run');
        } finally {
            unlink($path);
        }

        self::assertSame(503, $status);
        self::assertSame('CABLE_ROUTES_STORE_UNAVAILABLE', $error['code']);
    }

    public function test_the_store_is_opened_read_only(): void
    {
        $path = FixtureStore::create();
        $app = TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => $path]);
        $before = hash_file('sha256', $path);

        $this->get($app, 'run');
        $this->get($app, 'item?item=' . rawurlencode(FixtureStore::ITEM));

        self::assertSame($before, hash_file('sha256', $path));
        self::assertSame([], glob($path . '*-journal') ?: []);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        return TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => FixtureStore::create()]);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed> the envelope's data
     */
    private function get(App $app, string $path): array
    {
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));
        $body = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        self::assertTrue($body['success']);

        return Payload::obj($body['data']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{int, array<string, mixed>} status and the envelope's error
     */
    private function getError(App $app, string $path): array
    {
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));
        $body = TestAppFactory::json($response);
        self::assertFalse($body['success']);

        return [$response->getStatusCode(), Payload::obj($body['error'])];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $repo = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repo);
        $repo->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }

    public function test_the_permission_constant_matches_the_contract(): void
    {
        self::assertSame('CABLE_ROUTES.READ', Module::PERMISSION_READ);
    }
}
