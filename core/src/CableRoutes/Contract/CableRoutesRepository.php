<?php

declare(strict_types=1);

namespace TriCab\Core\CableRoutes\Contract;

use TriCab\Core\CableRoutes\Application\Query\ChecksFilter;
use TriCab\Core\CableRoutes\Application\Query\ItemsFilter;
use TriCab\Core\CableRoutes\Application\Query\MachineItemsFilter;

/**
 * Read access to the cable routes store. Each method returns the camelCase payload of one endpoint
 * (docs/cable-routes/CONTRACT.md, section 5.2) as plain arrays, ready for the JSON envelope.
 *
 * Every method may throw CableRoutesStoreUnavailable. Parameters are already validated by the query service.
 */
interface CableRoutesRepository
{
    /** @return array<string, mixed> */
    public function run(): array;

    /** @return array<string, mixed> */
    public function codes(): array;

    /** @return array<string, mixed> */
    public function items(ItemsFilter $filter): array;

    /**
     * @return array<string, mixed>
     * @throws \TriCab\Core\CableRoutes\Application\Exception\CableRoutesItemNotFound
     */
    public function item(string $item): array;

    /** @return array<string, mixed> */
    public function machines(): array;

    /**
     * @return array<string, mixed>
     * @throws \TriCab\Core\CableRoutes\Application\Exception\CableRoutesMachineNotFound
     */
    public function machine(string $id, MachineItemsFilter $filter): array;

    /** @return array<string, mixed> */
    public function checks(ChecksFilter $filter): array;
}
