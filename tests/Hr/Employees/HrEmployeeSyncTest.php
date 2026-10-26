<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\Employees;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\Employees\HrEmployeeSync;
use TriCab\Api\Hr\Employees\HrEmployeeSyncBusy;
use TriCab\Api\Hr\Employees\HrEmployeeSyncRefused;
use TriCab\Api\Hr\Employees\NullUserLoginLinker;
use TriCab\Api\Hr\Employees\UserLoginLinker;

final class HrEmployeeSyncTest extends TestCase
{
    private InMemoryHrEmployeeStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryHrEmployeeStore();
    }

    /**
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private static function row(string $code, string $login, string $updated = '2026-10-15', array $more = []): array
    {
        return EmployeeMapperTest::row($more + ['employee_code' => $code, 'user_login' => $login, 'update_date' => $updated]);
    }

    /** @return list<array<string, mixed>> E1..E$count, all updated on $date */
    private function ten(string $date, int $count): array
    {
        return array_map(static fn (int $i): array => self::row("E{$i}", "e{$i}", $date), range(1, $count));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $departments
     *
     * @return array<string, mixed>
     */
    private function sync(array $rows, bool $apply = true, ?UserLoginLinker $linker = null, array $departments = []): array
    {
        return (new HrEmployeeSync($this->store, $linker ?? new NullUserLoginLinker()))
            ->refresh(new InMemoryHrEmployeeSource($rows, $departments), $apply);
    }

    public function testDryRunReportsAndWritesNothing(): void
    {
        $result = $this->sync([self::row('A', 'a'), self::row('B', 'b')], false, null, [['department' => 'EXTRUDER', 'manager' => 'a', 'alt_manager' => null]]);

        self::assertSame('dry-run', $result['mode']);
        self::assertSame(2, $result['rowsInserted']);
        self::assertSame(['inserted' => 1, 'updated' => 0, 'unchanged' => 0], $result['departmentManagers']);
        self::assertSame(0, $this->store->writes);
        self::assertSame([], $this->store->runs);
    }

    public function testApplyInsertsAndRecordsTheRunWithCountsOnly(): void
    {
        $result = $this->sync([self::row('A', 'a'), self::row('B', 'b')]);

        self::assertSame(2, $result['rowsInserted']);
        self::assertCount(2, $this->store->employees);
        self::assertCount(1, $this->store->runs);
        self::assertSame('succeeded', $this->store->runs[0]['status']);
        // Restricted values live in the restricted copy only, and never in the reported line.
        self::assertSame('0400 000 000', $this->store->restricted['A']['Mobile']);
        self::assertStringNotContainsString('0400', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('gmail', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testUnchangedHashIsNotWrittenAgain(): void
    {
        $rows = [self::row('A', 'a'), self::row('B', 'b')];
        $this->sync($rows);
        $writes = $this->store->writes;

        $result = $this->sync($rows);

        self::assertSame(2, $result['rowsUnchanged']);
        self::assertSame(0, $result['rowsUpdated']);
        self::assertSame($writes, $this->store->writes);
    }

    public function testChangedRowIsUpdated(): void
    {
        $this->sync([self::row('A', 'a')]);

        $result = $this->sync([self::row('A', 'a', '2026-10-15', ['mobile' => '0499 999 999'])]);

        self::assertSame(1, $result['rowsUpdated']);
        self::assertSame('0499 999 999', $this->store->restricted['A']['Mobile']);
    }

    public function testAllDigitCodesSurviveAsArrayKeys(): void
    {
        $this->sync([self::row('12345', 'a', '2026-10-01'), self::row('67890', 'b', '2026-10-01')]);

        // Unchanged rows whose flags move, and a row that left: both take the code as a string.
        $result = $this->sync([self::row('12345', 'a', '2026-10-01'), self::row('2', 'c', '2026-10-15'), self::row('3', 'd', '2026-10-15')]);

        self::assertSame(1, $result['flagsChanged']);
        self::assertSame(1, $result['rowsMissing']);
    }

    public function testCurrentIsTheNewestUpdateDate(): void
    {
        $result = $this->sync([self::row('A', 'a', '2026-10-15'), self::row('B', 'b', '2026-10-15'), self::row('C', 'c', '2026-03-01'), self::row('D', 'd', '2020-01-01')]);

        self::assertSame('2026-10-15', $result['latestPayrollDate']);
        self::assertSame(2, $result['currentCount']);
        self::assertTrue($this->store->employees['A']['isCurrent']);
        self::assertFalse($this->store->employees['C']['isCurrent']);
    }

    public function testFlagsMoveOnWhenAFresherRefreshArrives(): void
    {
        $this->sync($this->ten('2026-10-01', 10));

        $this->sync($this->ten('2026-10-15', 9));

        self::assertTrue($this->store->employees['E1']['isCurrent']);
        self::assertFalse($this->store->employees['E10']['isCurrent']);
    }

    public function testShortRefreshKeepsEveryFlagAndWarns(): void
    {
        $rows = [];
        foreach (range(1, 10) as $i) {
            $rows[] = self::row("E{$i}", "e{$i}", '2026-10-01');
        }
        $this->sync($rows);
        self::assertSame(10, $this->store->runs[0]['currentCount']);

        // The next export touches only 7 of the 10 (70%, under the 80% line); one row also changes.
        $next = [];
        foreach (range(1, 10) as $i) {
            $next[] = $i <= 7 ? self::row("E{$i}", "e{$i}", '2026-10-15', $i === 1 ? ['mobile' => '0400 123 456'] : []) : self::row("E{$i}", "e{$i}", '2026-10-01');
        }
        $result = $this->sync($next);

        self::assertSame('guarded', $result['status']);
        self::assertNotNull($result['warning']);
        self::assertSame('guarded', $this->store->runs[1]['status']);
        self::assertSame(10, $result['currentCount']);
        foreach ($this->store->employees as $employee) {
            self::assertTrue($employee['isCurrent'], 'no IsCurrent flag changed');
        }
        self::assertSame('0400 123 456', $this->store->restricted['E1']['Mobile'], 'other fields are still copied');
    }

    public function testRefreshAtTheThresholdIsNotGuarded(): void
    {
        $rows = [];
        foreach (range(1, 10) as $i) {
            $rows[] = self::row("E{$i}", "e{$i}", '2026-10-01');
        }
        $this->sync($rows);
        $next = [];
        foreach (range(1, 10) as $i) {
            $next[] = self::row("E{$i}", "e{$i}", $i <= 8 ? '2026-10-15' : '2026-10-01');
        }

        $result = $this->sync($next);

        self::assertSame('succeeded', $result['status']);
        self::assertSame(8, $result['currentCount']);
        self::assertFalse($this->store->employees['E9']['isCurrent']);
    }

    public function testRowsGoneFromTheSourceAreFlaggedOnceAndKept(): void
    {
        $this->sync($this->ten('2026-10-15', 10));

        $first = $this->sync($this->ten('2026-10-15', 9));
        $writes = $this->store->writes;
        $second = $this->sync($this->ten('2026-10-15', 9));

        self::assertSame(1, $first['rowsMissing']);
        self::assertSame(0, $second['rowsMissing']);
        self::assertSame($writes, $this->store->writes, 'flagged once, not again');
        self::assertArrayHasKey('E10', $this->store->employees, 'never deleted');
        self::assertTrue($this->store->employees['E10']['missing']);
        self::assertFalse($this->store->employees['E10']['isCurrent']);
        self::assertArrayHasKey('E10', $this->store->restricted);
    }

    public function testAReturningRowIsNoLongerMissing(): void
    {
        $this->sync($this->ten('2026-10-15', 10));
        $this->sync($this->ten('2026-10-15', 9));

        $this->sync($this->ten('2026-10-15', 10));

        self::assertFalse($this->store->employees['E10']['missing']);
        self::assertTrue($this->store->employees['E10']['isCurrent']);
    }

    public function testDanglingManagerIsKeptAsRawText(): void
    {
        $this->sync([self::row('A', 'a', '2026-10-15', ['manager' => 'nobody.here'])]);

        self::assertSame('nobody.here', $this->store->base['A']['ManagerLogin']);
        self::assertNull($this->store->employees['A']['userId']);
    }

    public function testLoginsLinkCaseInsensitivelyAndUnmatchedAreCounted(): void
    {
        $linker = new class implements UserLoginLinker {
            /** @var list<string> */
            public array $asked = [];

            public function link(array $normalizedLogins): array
            {
                $this->asked = $normalizedLogins;

                return ['eloised' => 42];
            }
        };

        $result = $this->sync([self::row('A', 'ELOISED'), self::row('B', 'someone'), self::row('C', 'old', '2020-01-01')], true, $linker);

        self::assertSame(['eloised', 'someone', 'old'], $linker->asked);
        self::assertSame(42, $this->store->employees['A']['userId']);
        self::assertNull($this->store->employees['B']['userId']);
        self::assertSame('ELOISED', $this->store->base['A']['UserLogin']);
        self::assertSame(1, $result['unmatchedLogins'], 'current employees without a platform user');
        self::assertSame(2, $result['unmatchedLoginsAll']);
    }

    public function testAnEarlierLinkIsNotLostWhenTheLinkerFindsNothing(): void
    {
        $this->sync([self::row('A', 'a')], true, new class implements UserLoginLinker {
            public function link(array $normalizedLogins): array
            {
                return ['a' => 7];
            }
        });

        $this->sync([self::row('A', 'a')]);

        self::assertSame(7, $this->store->employees['A']['userId']);
    }

    public function testDepartmentManagersAreMirroredWithoutDeleting(): void
    {
        $this->store->upsertDepartmentManager('GONE', 'x', null, false);

        $first = $this->sync([self::row('A', 'a')], true, null, [['department' => ' EXTRUDER ', 'manager' => 'a', 'alt_manager' => '']]);
        $second = $this->sync([self::row('A', 'a')], true, null, [['department' => 'EXTRUDER', 'manager' => 'b', 'alt_manager' => null]]);

        self::assertSame(['inserted' => 1, 'updated' => 0, 'unchanged' => 0], $first['departmentManagers']);
        self::assertSame(['inserted' => 0, 'updated' => 1, 'unchanged' => 0], $second['departmentManagers']);
        self::assertSame(['manager' => 'b', 'alt' => null], $this->store->departments['EXTRUDER'] ?? null);
        self::assertArrayHasKey('GONE', $this->store->departments);
    }

    public function testAnEmptySourceIsRefused(): void
    {
        $this->sync([self::row('A', 'a')]);

        $this->expectException(HrEmployeeSyncRefused::class);
        $this->sync([]);
    }

    public function testABusyLockStopsTheRun(): void
    {
        $this->store->busy = true;

        try {
            $this->sync([self::row('A', 'a')]);
            self::fail('expected busy');
        } catch (HrEmployeeSyncBusy) {
            self::assertSame(0, $this->store->writes);
            self::assertSame(0, $this->store->failures, 'a busy lock is not a failed run');
        }
    }
}
