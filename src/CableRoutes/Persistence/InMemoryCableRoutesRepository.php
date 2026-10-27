<?php

declare(strict_types=1);

namespace TriCab\Api\CableRoutes\Persistence;

use TriCab\Core\CableRoutes\Application\Exception\CableRoutesItemNotFound;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesMachineNotFound;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesStoreUnavailable;
use TriCab\Core\CableRoutes\Application\Query\ChecksFilter;
use TriCab\Core\CableRoutes\Application\Query\ItemsFilter;
use TriCab\Core\CableRoutes\Application\Query\MachineItemsFilter;
use TriCab\Core\CableRoutes\Contract\CableRoutesRepository;

/**
 * A repository that serves canned payloads, for tests of code that sits above the store. It does no filtering or paging:
 * each endpoint returns the payload it was given. A store that is not "available" answers every call as a missing file does.
 */
final class InMemoryCableRoutesRepository implements CableRoutesRepository
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $codes
     * @param array<string, mixed> $items
     * @param array<string, array<string, mixed>> $itemsByNumber payload per finished item number
     * @param array<string, mixed> $machines
     * @param array<string, array<string, mixed>> $machinesById payload per machine id
     * @param array<string, mixed> $checks
     */
    public function __construct(
        private readonly array $run = [],
        private readonly array $codes = [],
        private readonly array $items = [],
        private readonly array $itemsByNumber = [],
        private readonly array $machines = [],
        private readonly array $machinesById = [],
        private readonly array $checks = [],
        private readonly bool $available = true,
    ) {
    }

    public function run(): array
    {
        return $this->serve('run', $this->run);
    }

    public function codes(): array
    {
        return $this->serve('codes', $this->codes);
    }

    public function items(ItemsFilter $filter): array
    {
        return $this->serve('items', $this->items);
    }

    public function item(string $item): array
    {
        $this->serve('item', []);

        return $this->itemsByNumber[$item] ?? throw new CableRoutesItemNotFound('Unknown item.');
    }

    public function machines(): array
    {
        return $this->serve('machines', $this->machines);
    }

    public function machine(string $id, MachineItemsFilter $filter): array
    {
        $this->serve('machine', []);

        return $this->machinesById[$id] ?? throw new CableRoutesMachineNotFound('Unknown machine.');
    }

    public function checks(ChecksFilter $filter): array
    {
        return $this->serve('checks', $this->checks);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function serve(string $call, array $payload): array
    {
        $this->calls[] = $call;
        if (!$this->available) {
            throw new CableRoutesStoreUnavailable('The store is not available.');
        }

        return $payload;
    }
}
