<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableRoutes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesInvalidRequest;
use TriCab\Core\CableRoutes\Application\Query\ChecksFilter;
use TriCab\Core\CableRoutes\Application\Query\ItemsFilter;
use TriCab\Core\CableRoutes\Application\Query\MachineItemsFilter;
use TriCab\Core\CableRoutes\Application\Service\CableRoutesQueryService;
use TriCab\Core\CableRoutes\Contract\CableRoutesRepository;
use TriCab\Core\CableRoutes\Module;
use TriCab\Core\CableRoutes\Vocabulary;

final class CableRoutesQueryServiceTest extends TestCase
{
    public function test_module_declares_its_one_permission(): void
    {
        self::assertSame('CABLE_ROUTES.READ', Module::PERMISSION_READ);
        self::assertSame(['CABLE_ROUTES.READ'], Module::PERMISSIONS);
    }

    public function test_items_passes_a_validated_filter_with_the_defaults(): void
    {
        $repository = new RecordingRepository();

        (new CableRoutesQueryService($repository))->items('KA', null, 'x', null, null, null);

        self::assertEquals(new ItemsFilter('KA', null, 'x', null, 1, 50), $repository->items);
    }

    public function test_items_lowercases_a_known_trace_status(): void
    {
        $repository = new RecordingRepository();

        (new CableRoutesQueryService($repository))->items(null, null, null, 'To_Rod', '3', '200');

        self::assertEquals(new ItemsFilter(null, null, null, 'to_rod', 3, 200), $repository->items);
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string}>
     */
    public static function badItemParameters(): iterable
    {
        yield 'unknown status' => ['nope', null, null];
        yield 'page zero' => [null, '0', null];
        yield 'page negative' => [null, '-1', null];
        yield 'page text' => [null, 'abc', null];
        yield 'page decimal' => [null, '1.5', null];
        yield 'pageSize zero' => [null, null, '0'];
        yield 'pageSize over max' => [null, null, '201'];
        yield 'pageSize text' => [null, null, 'many'];
    }

    #[DataProvider('badItemParameters')]
    public function test_items_rejects_bad_parameters(?string $status, ?string $page, ?string $pageSize): void
    {
        $repository = new RecordingRepository();

        try {
            (new CableRoutesQueryService($repository))->items(null, null, null, $status, $page, $pageSize);
            self::fail('Expected CableRoutesInvalidRequest.');
        } catch (CableRoutesInvalidRequest $e) {
            self::assertSame('CABLE_ROUTES_PARAM_INVALID', $e->errorCode);
        }
        self::assertNull($repository->items, 'the repository is not asked');
    }

    #[DataProvider('blank')]
    public function test_item_is_required(?string $value): void
    {
        $this->expectException(CableRoutesInvalidRequest::class);
        $this->expectExceptionMessage('item is required.');

        (new CableRoutesQueryService(new RecordingRepository()))->item($value);
    }

    #[DataProvider('blank')]
    public function test_machine_id_is_required(?string $value): void
    {
        try {
            (new CableRoutesQueryService(new RecordingRepository()))->machine($value, null, null, null);
            self::fail('Expected CableRoutesInvalidRequest.');
        } catch (CableRoutesInvalidRequest $e) {
            self::assertSame('CABLE_ROUTES_MACHINE_REQUIRED', $e->errorCode);
        }
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

    public function test_item_is_trimmed(): void
    {
        $repository = new RecordingRepository();

        (new CableRoutesQueryService($repository))->item('  KA9X-PASN/5C16BK ');

        self::assertSame('KA9X-PASN/5C16BK', $repository->item);
    }

    public function test_machine_defaults_and_limits(): void
    {
        $repository = new RecordingRepository();
        $service = new CableRoutesQueryService($repository);

        $service->machine(' EX6 ', 'KA', null, null);
        self::assertSame('EX6', $repository->machineId);
        self::assertEquals(new MachineItemsFilter('KA', 1, 50), $repository->machine);

        $this->expectException(CableRoutesInvalidRequest::class);
        $service->machine('EX6', null, null, '201');
    }

    public function test_checks_validates_the_kind_and_uses_its_own_page_sizes(): void
    {
        $repository = new RecordingRepository();
        $service = new CableRoutesQueryService($repository);

        $service->checks(null, null, null, null);
        self::assertEquals(new ChecksFilter(null, null, 1, 100), $repository->checks);

        $service->checks('stops_early', 'KA', '2', '500');
        self::assertEquals(new ChecksFilter('stops_early', 'KA', 2, 500), $repository->checks);

        try {
            $service->checks('nope', null, null, null);
            self::fail('Expected CableRoutesInvalidRequest.');
        } catch (CableRoutesInvalidRequest $e) {
            self::assertSame('CABLE_ROUTES_KIND_INVALID', $e->errorCode);
        }
    }

    public function test_vocabulary_lists_seven_check_kinds_and_four_statuses(): void
    {
        self::assertCount(7, Vocabulary::CHECK_KINDS);
        self::assertSame(['to_rod', 'to_purchased_conductor', 'stops_early', 'untraceable'], Vocabulary::TRACE_STATUSES);
    }
}

/** Records what the service asked for. */
final class RecordingRepository implements CableRoutesRepository
{
    public ?ItemsFilter $items = null;
    public ?string $item = null;
    public ?string $machineId = null;
    public ?MachineItemsFilter $machine = null;
    public ?ChecksFilter $checks = null;

    public function run(): array
    {
        return [];
    }

    public function codes(): array
    {
        return [];
    }

    public function items(ItemsFilter $filter): array
    {
        $this->items = $filter;

        return [];
    }

    public function item(string $item): array
    {
        $this->item = $item;

        return [];
    }

    public function machines(): array
    {
        return [];
    }

    public function machine(string $id, MachineItemsFilter $filter): array
    {
        $this->machineId = $id;
        $this->machine = $filter;

        return [];
    }

    public function checks(ChecksFilter $filter): array
    {
        $this->checks = $filter;

        return [];
    }
}
