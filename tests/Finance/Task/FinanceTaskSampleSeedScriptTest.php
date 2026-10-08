<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;

/**
 * `seed-finance-task-sample-local.sql` (T2.10) is local only and invented throughout. These checks read the
 * script; running it twice against the local database, with the same counts, is the T2.10 evidence.
 */
final class FinanceTaskSampleSeedScriptTest extends TestCase
{
    private static function sql(): string
    {
        $path = dirname(__DIR__, 3) . '/database/scripts/development/seed-finance-task-sample-local.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    private static function code(): string
    {
        return preg_replace('~/\*.*?\*/~s', '', self::sql()) ?? '';
    }

    public function test_it_says_it_is_local_only_and_refuses_azure_sql(): void
    {
        $sql = self::sql();

        self::assertStringContainsString('LOCAL ONLY. NOT FOR ANY SHARED DATABASE.', $sql);
        self::assertStringContainsString("SERVERPROPERTY('EngineEdition') AS INT) NOT IN (2, 3, 4)", self::code());
        self::assertStringContainsString("@@SERVERNAME LIKE N'%database.windows.net%'", self::code());
        self::assertStringContainsString("@@SERVERNAME LIKE N'%tricab-mel%'", self::code());
        self::assertStringContainsString('THROW 54120', self::code());
        // The guard runs before anything is written.
        self::assertLessThan(strpos(self::code(), 'BEGIN TRANSACTION'), strpos(self::code(), 'THROW 54120'));
    }

    public function test_every_person_is_a_local_test_user_and_no_address_is_real(): void
    {
        preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]+/', self::code(), $matches);

        self::assertNotSame([], $matches[0]);
        foreach (array_unique($matches[0]) as $address) {
            self::assertMatchesRegularExpression('/@sample\.local$/', $address, $address . ' is not a clearly local address');
        }
        self::assertStringNotContainsString('tricab.com', self::code());
        self::assertStringNotContainsString('Nick', self::code());
    }

    public function test_it_is_idempotent_by_construction_and_destroys_nothing(): void
    {
        $code = self::code();

        self::assertDoesNotMatchRegularExpression('/\b(DELETE|DROP|TRUNCATE|ALTER)\b/i', $code);
        // Every INSERT is guarded by a NOT EXISTS on a fixed id (or the natural key).
        self::assertGreaterThanOrEqual(14, substr_count($code, 'NOT EXISTS'));
        // The one UPDATE only moves one sample task's own timestamp, and only when it still has the seeded value.
        self::assertSame(1, preg_match_all('/\bUPDATE\b/i', $code));
        self::assertStringContainsString("[id] = '5a1d000000000000000000000000000a' AND [updated_date] =", $code);
    }

    public function test_dates_are_relative_to_the_run_in_melbourne_and_the_dataset_is_checked_whole(): void
    {
        $code = self::code();

        self::assertStringContainsString("AT TIME ZONE N'AUS Eastern Standard Time'", $code);
        self::assertDoesNotMatchRegularExpression("/'20\\d\\d-\\d\\d-\\d\\d'/", $code, 'no literal calendar date: "overdue" must stay overdue');
        self::assertStringContainsString('THROW 54123', $code);
        self::assertSame(12, preg_match_all("/\\(\\d+,\\s+'(5a1d[0-9a-f]{28})'/", $code), 'twelve tasks, each by a fixed id');
    }

    public function test_it_creates_the_three_roles_assignments_among_nine_test_users_and_touches_no_other_user(): void
    {
        $code = self::code();

        foreach (['FINANCE_TASK_ADMIN', 'FINANCE_TASK_MANAGER', 'FINANCE_TASK_USER'] as $role) {
            self::assertStringContainsString($role, $code);
        }
        self::assertSame(9, preg_match_all("/\\(N'[a-z]+',\\s+N'[a-z.]+@sample\\.local'/", $code));
        self::assertDoesNotMatchRegularExpression('/INSERT INTO \[identity\]\.\[(Roles|Permissions|RolePermissions)\]/i', $code);
    }
}
