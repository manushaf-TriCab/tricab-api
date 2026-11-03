<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/**
 * The directory in memory, for `USER_REPOSITORY=memory` and tests. Holds items
 * in the API shape (department and factory as the raw payroll values);
 * managerName and the clean department/factory names are resolved at read time,
 * as the SQL does. It is also the in-memory department map, because the map's
 * entries are derived from the employees it holds.
 */
final class InMemoryEmployeeDirectory implements EmployeeDirectory, DepartmentMapStore
{
    /** @var array<string, array{raw: string, cleanName: string, costCentre: ?string}> keyed "kind|lowercased raw" */
    private array $map = [];
    /** @var list<array{actorUserId: int, kind: string, raw: string, action: string, oldCleanName: ?string, oldCostCentre: ?string, newCleanName: ?string, newCostCentre: ?string}> */
    public array $mapEvents = [];

    /** @var array<string, array<string, mixed>> by code */
    private array $items = [];
    /** @var array<string, array<string, mixed>> by code */
    private array $restricted = [];
    /** @var list<array<string, mixed>> */
    private array $runs = [];

    /**
     * @param array<string, mixed> $item        code, login, firstName, lastName, displayName, department, factory,
     *                                          workEmail, managerLogin, dateHired, isCurrent, lastSeenInPayroll,
     *                                          missingFromSourceAt, linkedUser (any may be omitted)
     * @param array<string, mixed>|null $restricted
     */
    public function withEmployee(array $item, ?array $restricted = null): self
    {
        $code = self::text($item, 'code');
        $this->items[$code] = $item;
        if ($restricted !== null) {
            $this->restricted[$code] = $restricted;
        }

        return $this;
    }

    /** @param list<array<string, mixed>> $runs newest first */
    public function withSyncRuns(array $runs): self
    {
        $this->runs = $runs;

        return $this;
    }

    public function search(EmployeeListCriteria $criteria): array
    {
        $rows = array_values(array_filter($this->items, fn (array $item): bool => $this->matches($item, $criteria)));
        $key = fn (array $item): string => strtolower(match ($criteria->sortBy) {
            EmployeeListCriteria::SORT_CODE => self::text($item, 'code'),
            EmployeeListCriteria::SORT_DEPARTMENT => $this->effective($item, 'department'),
            EmployeeListCriteria::SORT_FACTORY => $this->effective($item, 'factory'),
            EmployeeListCriteria::SORT_MANAGER => self::text($item, 'managerLogin'),
            EmployeeListCriteria::SORT_DATE_HIRED => self::text($item, 'dateHired'),
            EmployeeListCriteria::SORT_LAST_SEEN => self::text($item, 'lastSeenInPayroll'),
            default => self::text($item, 'displayName') !== '' ? self::text($item, 'displayName') : self::text($item, 'code'),
        });
        usort($rows, static function (array $a, array $b) use ($key, $criteria): int {
            $order = $key($a) <=> $key($b);
            if ($criteria->descending()) {
                $order = -$order;
            }

            return $order !== 0 ? $order : self::text($a, 'code') <=> self::text($b, 'code');
        });

        return [
            'items' => array_map($this->shape(...), array_slice($rows, ($criteria->page - 1) * $criteria->pageSize, $criteria->pageSize)),
            'total' => count($rows),
        ];
    }

    public function facets(string $status): array
    {
        $departments = [];
        $factories = [];
        foreach ($this->items as $item) {
            if (!self::inStatus($item, $status)) {
                continue;
            }
            $department = $this->effective($item, 'department');
            $factory = $this->effective($item, 'factory');
            if ($department !== '') {
                $departments[$department] = ($departments[$department] ?? 0) + 1;
            }
            if ($factory !== '') {
                $factories[$factory] = ($factories[$factory] ?? 0) + 1;
            }
        }

        return ['departments' => self::facetList($departments), 'factories' => self::facetList($factories)];
    }

    public function find(string $code): ?array
    {
        return isset($this->items[$code]) ? $this->shape($this->items[$code]) : null;
    }

    public function findByLogin(string $login): ?array
    {
        $found = $this->rawByLogin($login);

        return $found === null ? null : $this->shape($found);
    }

    public function directReports(string $login): array
    {
        $reports = array_filter(
            $this->items,
            static fn (array $item): bool => ($item['isCurrent'] ?? false) === true
                && self::text($item, 'managerLogin') !== ''
                && strtolower(self::text($item, 'managerLogin')) === strtolower(trim($login)),
        );
        usort($reports, static fn (array $a, array $b): int => self::text($a, 'displayName') <=> self::text($b, 'displayName'));

        return array_map($this->shape(...), $reports);
    }

    public function restricted(string $code): ?array
    {
        if (!isset($this->restricted[$code])) {
            return null;
        }

        return array_merge(array_fill_keys(RestrictedFields::KEYS, null), $this->restricted[$code]);
    }

    public function syncRuns(int $limit): array
    {
        return array_slice($this->runs, 0, $limit);
    }

    public function totals(): array
    {
        $latest = null;
        $current = 0;
        foreach ($this->items as $item) {
            $current += ($item['isCurrent'] ?? false) === true ? 1 : 0;
            $seen = self::text($item, 'lastSeenInPayroll');
            if ($seen !== '' && ($latest === null || $seen > $latest)) {
                $latest = $seen;
            }
        }

        return ['latestPayrollDate' => $latest, 'currentCount' => $current];
    }

    public function entries(): array
    {
        $seen = [];
        foreach ($this->items as $item) {
            foreach ([self::KIND_DEPARTMENT, self::KIND_FACTORY] as $kind) {
                $raw = self::text($item, $kind);
                if ($raw === '') {
                    continue;
                }
                $key = self::mapKey($kind, $raw);
                $seen[$key] ??= ['kind' => $kind, 'raw' => $raw, 'employees' => 0, 'currentEmployees' => 0];
                $seen[$key]['employees']++;
                $seen[$key]['currentEmployees'] += ($item['isCurrent'] ?? false) === true ? 1 : 0;
            }
        }
        foreach ($this->map as $key => $mapped) {
            $seen[$key] ??= ['kind' => explode('|', $key, 2)[0], 'raw' => $mapped['raw'], 'employees' => 0, 'currentEmployees' => 0];
        }

        $entries = [];
        foreach ($seen as $key => $row) {
            $mapped = $this->map[$key] ?? null;
            $entries[] = [
                ...$row,
                'cleanName' => $mapped['cleanName'] ?? null,
                'costCentre' => $mapped['costCentre'] ?? null,
                'mapped' => $mapped !== null,
                'updatedAt' => null,
                'updatedBy' => null,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => [$a['kind'], strtolower($a['raw'])] <=> [$b['kind'], strtolower($b['raw'])]);

        return $entries;
    }

    public function save(ActorContext $actor, string $kind, string $raw, ?string $cleanName, ?string $costCentre): array
    {
        $key = self::mapKey($kind, $raw);
        $old = $this->map[$key] ?? null;
        if ($old === null && !$this->seen($kind, $raw)) {
            throw DepartmentMapRefused::unknownValue($kind, $raw);
        }
        $result = ['kind' => $kind, 'raw' => $raw, 'cleanName' => $cleanName, 'costCentre' => $costCentre, 'mapped' => $cleanName !== null];
        if (($old === null && $cleanName === null)
            || ($old !== null && $old['cleanName'] === $cleanName && $old['costCentre'] === $costCentre)) {
            return [...$result, 'changed' => false];
        }

        if ($cleanName === null) {
            unset($this->map[$key]);
            $action = 'cleared';
        } else {
            $this->map[$key] = ['raw' => $raw, 'cleanName' => $cleanName, 'costCentre' => $costCentre];
            $action = $old === null ? 'created' : 'updated';
        }
        $this->mapEvents[] = [
            'actorUserId' => $actor->userId, 'kind' => $kind, 'raw' => $raw, 'action' => $action,
            'oldCleanName' => $old['cleanName'] ?? null, 'oldCostCentre' => $old['costCentre'] ?? null,
            'newCleanName' => $cleanName, 'newCostCentre' => $costCentre,
        ];

        return [...$result, 'changed' => true];
    }

    private function seen(string $kind, string $raw): bool
    {
        foreach ($this->items as $item) {
            if (strtolower(self::text($item, $kind)) === strtolower($raw)) {
                return true;
            }
        }

        return false;
    }

    /** The clean name when mapped, else the trimmed raw value ('' when blank). */
    /** @param array<mixed> $item */
    private function effective(array $item, string $kind): string
    {
        $raw = self::text($item, $kind);

        return $raw === '' ? '' : ($this->map[self::mapKey($kind, $raw)]['cleanName'] ?? $raw);
    }

    private static function mapKey(string $kind, string $raw): string
    {
        return $kind . '|' . strtolower(trim($raw));
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function shape(array $item): array
    {
        $manager = ($item['managerLogin'] ?? null) === null ? null : $this->rawByLogin(self::text($item, 'managerLogin'));

        return [
            'code' => $item['code'] ?? null,
            'login' => $item['login'] ?? null,
            'firstName' => $item['firstName'] ?? null,
            'lastName' => $item['lastName'] ?? null,
            'displayName' => $item['displayName'] ?? null,
            'department' => $this->effective($item, 'department') ?: null,
            'departmentRaw' => self::text($item, 'department') ?: null,
            'factory' => $this->effective($item, 'factory') ?: null,
            'factoryRaw' => self::text($item, 'factory') ?: null,
            'workEmail' => $item['workEmail'] ?? null,
            'managerLogin' => $item['managerLogin'] ?? null,
            'managerName' => $manager === null ? null : ($manager['displayName'] ?? null),
            'dateHired' => $item['dateHired'] ?? null,
            'isCurrent' => ($item['isCurrent'] ?? false) === true,
            'lastSeenInPayroll' => $item['lastSeenInPayroll'] ?? null,
            'missingFromSourceAt' => $item['missingFromSourceAt'] ?? null,
            'linkedUser' => $item['linkedUser'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawByLogin(string $login): ?array
    {
        $found = null;
        foreach ($this->items as $item) {
            if (strtolower(self::text($item, 'login')) !== strtolower(trim($login))) {
                continue;
            }
            if ($found === null || (($item['isCurrent'] ?? false) === true && ($found['isCurrent'] ?? false) !== true)) {
                $found = $item;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function matches(array $item, EmployeeListCriteria $criteria): bool
    {
        if (!self::inStatus($item, $criteria->status)) {
            return false;
        }
        if ($criteria->department !== null && $this->effective($item, 'department') !== $criteria->department) {
            return false;
        }
        if ($criteria->factory !== null && $this->effective($item, 'factory') !== $criteria->factory) {
            return false;
        }
        if ($criteria->manager !== null && strtolower(self::text($item, 'managerLogin')) !== strtolower($criteria->manager)) {
            return false;
        }
        if ($criteria->search !== null) {
            foreach (['code', 'login', 'firstName', 'lastName', 'displayName'] as $field) {
                if (str_contains(strtolower(self::text($item, $field)), strtolower($criteria->search))) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param array<mixed> $item
     */
    private static function inStatus(array $item, string $status): bool
    {
        $current = ($item['isCurrent'] ?? false) === true;

        return $status === EmployeeListCriteria::STATUS_ALL
            || ($status === EmployeeListCriteria::STATUS_CURRENT) === $current;
    }

    /**
     * @param array<mixed> $item
     */
    private static function text(array $item, string $key): string
    {
        $value = $item[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{value: string, count: int}>
     */
    private static function facetList(array $counts): array
    {
        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
        $list = [];
        foreach ($counts as $value => $count) {
            $list[] = ['value' => (string) $value, 'count' => $count];
        }

        return $list;
    }
}
