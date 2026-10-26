<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/**
 * The HR Employees reads. Permission checks live on the routes; the one
 * decision made here is whether the restricted block is included, and the audit
 * row for it is written before the block leaves this class, so a failed audit
 * write fails the request instead of leaving a read unrecorded.
 *
 * ## Item shape
 *
 * code, login, firstName, lastName, displayName, department, factory, workEmail,
 * managerLogin, managerName (null when the manager login matches nobody),
 * dateHired, isCurrent, lastSeenInPayroll, missingFromSourceAt, linkedUser
 * ({publicId, displayName} or null).
 */
final class EmployeeDirectoryService
{
    public const DETAIL_ENDPOINT = 'GET /api/v1/hr/employees/{code}';

    /** Cycles are cut by the visited set; this bounds an honest but absurdly deep chain. */
    private const MAX_CHAIN_DEPTH = 10;
    private const SYNC_RUN_LIMIT = 20;

    public function __construct(
        private readonly EmployeeDirectory $directory,
        private readonly RestrictedReadAudit $audit,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, facets: array<string, list<array{value: string, count: int}>>}
     */
    public function list(EmployeeListCriteria $criteria): array
    {
        $result = $this->directory->search($criteria);

        return [
            'items' => $result['items'],
            'pagination' => [
                'page' => $criteria->page,
                'pageSize' => $criteria->pageSize,
                'totalItems' => $result['total'],
                'totalPages' => (int) max(1, ceil($result['total'] / $criteria->pageSize)),
            ],
            'facets' => $this->directory->facets($criteria->status),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws EmployeeNotFound
     */
    public function detail(string $code, ?ActorContext $restrictedFor): array
    {
        $employee = $this->directory->find($code);
        if ($employee === null) {
            throw EmployeeNotFound::forCode($code);
        }

        $login = $employee['login'] ?? null;
        $employee['managerChain'] = $this->managerChain($employee);
        $employee['directReports'] = is_string($login) && $login !== ''
            ? array_map(self::summary(...), $this->directory->directReports($login))
            : [];

        if ($restrictedFor !== null) {
            $resolvedCode = is_string($employee['code'] ?? null) ? $employee['code'] : $code;
            $this->audit->record($restrictedFor, $resolvedCode, self::DETAIL_ENDPOINT);
            $employee['restricted'] = $this->directory->restricted($resolvedCode) ?? self::emptyRestricted();
        }

        return $employee;
    }

    /**
     * @return array{runs: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function syncRuns(): array
    {
        $runs = $this->directory->syncRuns(self::SYNC_RUN_LIMIT);
        $totals = $this->directory->totals();

        $lastSuccessful = null;
        foreach ($runs as $run) {
            if (($run['status'] ?? null) === 'succeeded' && ($run['mode'] ?? null) === 'apply') {
                $lastSuccessful = $run;
                break;
            }
        }
        $latest = $runs[0] ?? null;

        return [
            'runs' => $runs,
            'summary' => [
                'latestPayrollDate' => $totals['latestPayrollDate'],
                'currentCount' => $totals['currentCount'],
                'lastSuccessfulRun' => $lastSuccessful,
                'lastRunStatus' => $latest['status'] ?? null,
                'lastRunWarning' => $latest['warning'] ?? null,
            ],
        ];
    }

    /**
     * Up the reporting line, nearest first. Stops at a dangling manager, at a
     * manager already seen (a cycle) and at the depth limit.
     *
     * @param array<string, mixed> $employee
     *
     * @return list<array{code: string, login: ?string, displayName: ?string}>
     */
    private function managerChain(array $employee): array
    {
        $seen = [strtolower(self::string($employee['code'] ?? null) ?? '') => true];
        $chain = [];
        $next = self::string($employee['managerLogin'] ?? null);

        while ($next !== null && count($chain) < self::MAX_CHAIN_DEPTH) {
            $manager = $this->directory->findByLogin($next);
            $code = $manager === null ? null : self::string($manager['code'] ?? null);
            if ($manager === null || $code === null || isset($seen[strtolower($code)])) {
                break;
            }
            $seen[strtolower($code)] = true;
            $chain[] = [
                'code' => $code,
                'login' => self::string($manager['login'] ?? null),
                'displayName' => self::string($manager['displayName'] ?? null),
            ];
            $next = self::string($manager['managerLogin'] ?? null);
        }

        return $chain;
    }

    /**
     * @param array<string, mixed> $employee
     *
     * @return array<string, mixed>
     */
    private static function summary(array $employee): array
    {
        return [
            'code' => $employee['code'] ?? null,
            'login' => $employee['login'] ?? null,
            'displayName' => $employee['displayName'] ?? null,
            'department' => $employee['department'] ?? null,
            'factory' => $employee['factory'] ?? null,
        ];
    }

    /**
     * @return array<string, null>
     */
    private static function emptyRestricted(): array
    {
        return array_fill_keys(RestrictedFields::KEYS, null);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
