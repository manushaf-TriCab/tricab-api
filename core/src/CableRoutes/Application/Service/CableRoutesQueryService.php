<?php

declare(strict_types=1);

namespace TriCab\Core\CableRoutes\Application\Service;

use TriCab\Core\CableRoutes\Application\Exception\CableRoutesInvalidRequest;
use TriCab\Core\CableRoutes\Application\Query\ChecksFilter;
use TriCab\Core\CableRoutes\Application\Query\ItemsFilter;
use TriCab\Core\CableRoutes\Application\Query\MachineItemsFilter;
use TriCab\Core\CableRoutes\Contract\CableRoutesRepository;
use TriCab\Core\CableRoutes\Vocabulary;

/**
 * Validates the request parameters (raw strings from the query string, null when absent or blank) and asks the
 * repository. The only rules here are the ones in CONTRACT.md section 5.1.
 */
final class CableRoutesQueryService
{
    public function __construct(
        private readonly CableRoutesRepository $repository,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        return $this->repository->run();
    }

    /** @return array<string, mixed> */
    public function codes(): array
    {
        return $this->repository->codes();
    }

    /** @return array<string, mixed> */
    public function items(?string $code, ?string $construction, ?string $q, ?string $traceStatus, ?string $page, ?string $pageSize): array
    {
        $status = null;
        if ($traceStatus !== null) {
            $status = strtolower($traceStatus);
            if (!in_array($status, Vocabulary::TRACE_STATUSES, true)) {
                throw new CableRoutesInvalidRequest('CABLE_ROUTES_PARAM_INVALID', 'traceStatus is not a known trace status.');
            }
        }

        return $this->repository->items(new ItemsFilter(
            $code,
            $construction,
            $q,
            $status,
            self::page($page),
            self::pageSize($pageSize, Vocabulary::ITEMS_DEFAULT_PAGE_SIZE, Vocabulary::ITEMS_MAX_PAGE_SIZE),
        ));
    }

    /** @return array<string, mixed> */
    public function item(?string $item): array
    {
        if ($item === null || trim($item) === '') {
            throw new CableRoutesInvalidRequest('CABLE_ROUTES_ITEM_REQUIRED', 'item is required.');
        }

        return $this->repository->item(trim($item));
    }

    /** @return array<string, mixed> */
    public function machines(): array
    {
        return $this->repository->machines();
    }

    /** @return array<string, mixed> */
    public function machine(?string $id, ?string $code, ?string $page, ?string $pageSize): array
    {
        if ($id === null || trim($id) === '') {
            throw new CableRoutesInvalidRequest('CABLE_ROUTES_MACHINE_REQUIRED', 'id is required.');
        }

        return $this->repository->machine(trim($id), new MachineItemsFilter(
            $code,
            self::page($page),
            self::pageSize($pageSize, Vocabulary::ITEMS_DEFAULT_PAGE_SIZE, Vocabulary::ITEMS_MAX_PAGE_SIZE),
        ));
    }

    /** @return array<string, mixed> */
    public function checks(?string $kind, ?string $code, ?string $page, ?string $pageSize): array
    {
        if ($kind !== null && !array_key_exists($kind, Vocabulary::CHECK_KINDS)) {
            throw new CableRoutesInvalidRequest('CABLE_ROUTES_KIND_INVALID', 'kind is not a known check kind.');
        }

        return $this->repository->checks(new ChecksFilter(
            $kind,
            $code,
            self::page($page),
            self::pageSize($pageSize, Vocabulary::CHECKS_DEFAULT_PAGE_SIZE, Vocabulary::CHECKS_MAX_PAGE_SIZE),
        ));
    }

    private static function page(?string $raw): int
    {
        if ($raw === null) {
            return 1;
        }
        if (preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < 1) {
            throw new CableRoutesInvalidRequest('CABLE_ROUTES_PARAM_INVALID', 'page must be a whole number of 1 or more.');
        }

        return (int) $raw;
    }

    private static function pageSize(?string $raw, int $default, int $max): int
    {
        if ($raw === null) {
            return $default;
        }
        if (preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > $max) {
            throw new CableRoutesInvalidRequest('CABLE_ROUTES_PARAM_INVALID', 'pageSize must be a whole number from 1 to ' . $max . '.');
        }

        return (int) $raw;
    }
}
