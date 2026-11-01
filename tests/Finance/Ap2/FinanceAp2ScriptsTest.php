<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Module;

/** The Tri-AP SQL scripts say what `Module` says and are safe to read as text (nothing here runs SQL). */
final class FinanceAp2ScriptsTest extends TestCase
{
    private const CODE = 'FINANCE\\.[A-Z0-9_.]+';

    private const TABLES = [
        'ap2_settings', 'ap2_suppliers', 'ap2_approvals', 'ap2_invoices', 'ap2_mail', 'ap2_mail_attachments', 'ap2_statements',
        'ap2_approval_events', 'ap2_payments', 'ap2_history', 'ap2_notifications', 'ap2_exports', 'ap2_priorities',
        'ap2_po_forecast', 'ap2_copilot_messages', 'ap2_sync_runs',
    ];

    private static function script(string $name): string
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/' . $name);
        self::assertIsString($sql, $name);

        return $sql;
    }

    public function test_the_permission_seed_creates_exactly_the_eleven_ap2_permissions(): void
    {
        preg_match_all("/^\\s*\\(N'(" . self::CODE . ")',/m", self::script('seed-finance-ap2-permissions.sql'), $m);
        $seeded = $m[1];
        sort($seeded);
        $expected = Module::AP2_PERMISSIONS;
        sort($expected);

        self::assertCount(11, $expected);
        self::assertSame($expected, $seeded);
        foreach ($expected as $code) {
            self::assertStringStartsWith('FINANCE.AP2.', $code);
            self::assertNotContains($code, Module::PERSON_PERMISSIONS);
        }
        self::assertContains(Module::PERMISSION_AP2_ACCESS, Module::PERSON_PERMISSIONS);
    }

    public function test_the_role_seed_grants_exactly_the_module_ap2_role_permissions(): void
    {
        preg_match_all("/\\(N'(FINANCE_AP2_[A-Z_]+)', N'(" . self::CODE . ")'\\)/", self::script('seed-finance-ap2-roles.sql'), $m, PREG_SET_ORDER);
        $seeded = [];
        foreach ($m as $match) {
            $seeded[$match[1]][] = $match[2];
        }

        self::assertSame(Module::AP2_ROLE_PERMISSIONS, $seeded);
        preg_match_all("/^\\s*\\(N'(FINANCE_AP2_[A-Z_]+)',\\s*N'[^']+',\\s*$/m", self::script('seed-finance-ap2-roles.sql'), $roles);
        self::assertSame([Module::ROLE_AP2_OPERATOR, Module::ROLE_AP2_APPROVER, Module::ROLE_AP2_BANK_VERIFIER, Module::ROLE_AP2_ADMIN], $roles[1]);
    }

    public function test_the_roles_match_the_contract_table(): void
    {
        $own = static fn (string $role): array => array_values(array_diff(Module::AP2_ROLE_PERMISSIONS[$role], [Module::PERMISSION_ACCESS, Module::PERMISSION_AP2_ACCESS]));
        $p = static fn (string $short): string => 'FINANCE.AP2.' . $short;

        self::assertEqualsCanonicalizing(array_map($p, ['INVOICES.READ', 'INVOICES.WRITE', 'MAIL.READ', 'MAIL.SEND', 'APPROVALS.REQUEST', 'EXPORT', 'PAYMENTS.PREPARE']), $own(Module::ROLE_AP2_OPERATOR));
        self::assertEqualsCanonicalizing(array_map($p, ['INVOICES.READ', 'MAIL.READ', 'APPROVALS.DECIDE', 'PAYMENTS.APPROVE']), $own(Module::ROLE_AP2_APPROVER));
        self::assertEqualsCanonicalizing(array_map($p, ['INVOICES.READ', 'BANK.VERIFY']), $own(Module::ROLE_AP2_BANK_VERIFIER));
        self::assertEqualsCanonicalizing(Module::AP2_PERMISSIONS, $own(Module::ROLE_AP2_ADMIN));
        foreach (Module::AP2_ROLE_PERMISSIONS as $role => $permissions) {
            self::assertContains(Module::PERMISSION_ACCESS, $permissions, $role);
            self::assertContains(Module::PERMISSION_AP2_ACCESS, $permissions, $role);
        }
    }

    public function test_the_seeds_are_guarded_marked_not_applied_and_assign_nobody(): void
    {
        foreach (['seed-finance-ap2-permissions.sql', 'seed-finance-ap2-roles.sql'] as $name) {
            $sql = self::script($name);
            self::assertStringContainsString('NOT APPLIED to any shared database', $sql, $name);
            self::assertStringContainsString('THROW 54101', $sql, $name);
            self::assertStringContainsString('THROW 54102', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\bDELETE\\s+(FROM\\s+)?[@\\[#]/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\[identity\\]\\.\\[(Users|UserRoles)\\]/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression("/\\(N'(EMPLOYEE|GUEST|ADMINISTRATOR|FINANCE_ADMIN)',\\s*N'FINANCE\\./", $sql, $name);
        }
    }

    public function test_the_schema_creates_the_sixteen_contract_tables_each_only_if_missing(): void
    {
        $sql = self::script('create-finance-ap2-schema.sql');

        self::assertStringContainsString('NOT APPLIED to any shared database', $sql);
        preg_match_all('/CREATE TABLE \[finance\]\.\[([a-z0-9_]+)\]/', $sql, $created);
        self::assertEqualsCanonicalizing(self::TABLES, $created[1]);
        self::assertCount(16, array_unique($created[1]));
        foreach (self::TABLES as $table) {
            self::assertStringContainsString("IF OBJECT_ID(N'[finance].[" . $table . "]', N'U') IS NULL", $sql, $table);
        }
    }

    public function test_the_schema_follows_the_finance_conventions_and_destroys_nothing(): void
    {
        $sql = self::script('create-finance-ap2-schema.sql');
        $code = preg_replace('~/\*.*?\*/~s', '', $sql) ?? '';

        self::assertStringNotContainsString('DATETIME2(7)', $code);
        self::assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE)\b/i', $code);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\b/i', preg_replace('/INSTEAD OF UPDATE, DELETE/', '', $code) ?? '');
        self::assertDoesNotMatchRegularExpression('/REFERENCES\s+\[(?!finance\])/i', $code);
        self::assertDoesNotMatchRegularExpression('/ON (DELETE|UPDATE) CASCADE/i', $code);
        self::assertDoesNotMatchRegularExpression('/\bFLOAT\b|\bREAL\b|DECIMAL\((?!18,[24]\)|9,8\))/i', $code);
        // Every person is a user id with a name beside it.
        self::assertStringContainsString('[owner_user_id]', $code);
        self::assertStringContainsString('[actor_user_id]', $code);
    }

    public function test_currency_is_limited_to_the_four_on_every_money_table(): void
    {
        $code = self::script('create-finance-ap2-schema.sql');

        foreach (['ap2_invoices', 'ap2_statements', 'ap2_payments', 'ap2_po_forecast'] as $table) {
            self::assertStringContainsString("CK_finance_{$table}_currency] CHECK ([currency] IN ('AUD', 'USD', 'NZD', 'SGD'))", $code, $table);
        }
        self::assertSame(['AUD', 'USD', 'NZD', 'SGD'], \TriCab\Core\Finance\Ap2\Domain\Ap2Invoice::CURRENCIES);
    }

    public function test_the_history_table_is_append_only_in_the_script_and_in_code(): void
    {
        $code = self::script('create-finance-ap2-schema.sql');
        self::assertStringContainsString('APPEND ONLY', $code);
        self::assertStringContainsString('INSTEAD OF UPDATE, DELETE', $code);
        self::assertStringContainsString('TR_ap2_history_append_only', $code);

        $store = new \ReflectionClass(\TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore::class);
        self::assertSame(['append', 'forSupplier'], array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $store->getMethods()));
        foreach ([\TriCab\Api\Finance\Ap2\InMemoryAp2HistoryStore::class, \TriCab\Api\Finance\Ap2\AzureSqlAp2HistoryStore::class] as $class) {
            $source = file_get_contents((string) (new \ReflectionClass($class))->getFileName());
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('/\b(UPDATE|DELETE)\s+(\[finance\]|FROM)/i', $source, $class);
        }
    }

    public function test_the_app_key_is_allowed_everywhere_a_finance_app_key_is_checked_and_the_alter_script_matches(): void
    {
        foreach (['create-finance-schema.sql' => 3, 'create-finance-access-schema.sql' => 1, 'create-finance-attachments-schema.sql' => 1] as $name => $count) {
            self::assertSame($count, substr_count(self::script($name), "N'AP', N'AP2')"), $name);
        }
        $alter = self::script('alter-finance-ap2-app-key.sql');
        self::assertStringContainsString('NOT APPLIED to any shared database', $alter);
        foreach (['mailbox_grants', 'mail_deliveries', 'audit_events', 'access_scopes', 'attachments'] as $table) {
            self::assertStringContainsString("N'" . $table . "'", $alter, $table);
        }
    }

    public function test_the_local_rebuild_runs_the_ap2_scripts_in_order(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');
        $last = -1;
        foreach (['create-finance-attachments-schema.sql', 'create-finance-ap2-schema.sql', 'seed-finance-permissions.sql', 'seed-finance-ap2-permissions.sql', 'seed-finance-ap2-roles.sql'] as $name) {
            $at = strpos($rebuild, "'" . $name . "'");
            self::assertIsInt($at, $name);
            self::assertGreaterThan($last, $at, $name);
            $last = $at;
        }
    }

    public function test_the_round_3_scripts_are_narrow_and_say_they_are_not_applied(): void
    {
        $alter = self::script('alter-finance-ap2-mail-round3.sql');
        $seed = self::script('seed-finance-ap2-mailbox-grant.sql');

        self::assertStringContainsString('draft_attachment_ids', $alter);
        self::assertStringContainsString('NOT APPLIED', $alter);
        self::assertStringContainsString('NOT APPLIED', $seed);
        self::assertStringContainsString("N'AP2'", $seed, 'the grant is for the AP2 application');
        self::assertStringNotContainsString('christine@', $seed, 'ap@ only');
        self::assertStringNotContainsString('N\'AP\',', $seed, 'no other application is touched');
        self::assertDoesNotMatchRegularExpression('/\b(UPDATE|DELETE)\b/i', $seed, 'inserts only');
    }

    public function test_the_sync_batches_alter_is_idempotent_and_says_it_is_not_applied(): void
    {
        $alter = self::script('alter-finance-ap2-sync-batches.sql');
        $create = self::script('create-finance-ap2-schema.sql');

        self::assertStringContainsString('NOT APPLIED', $alter);
        foreach (['processed', 'failed', 'updated_at', 'Cancelled'] as $needle) {
            self::assertStringContainsString($needle, $alter);
            self::assertStringContainsString($needle, $create, 'a fresh build has it too');
        }
        self::assertStringContainsString('COL_LENGTH', $alter);
        self::assertStringNotContainsString('DELETE', $alter);
    }

    public function test_the_sample_seed_is_local_only_idempotent_and_has_the_prototype_data(): void
    {
        $sql = self::script('seed-finance-ap2-sample-local.sql');

        self::assertStringContainsString('LOCAL ONLY', $sql);
        self::assertStringContainsString("@@SERVERNAME LIKE N'%database.windows.net%'", $sql);
        self::assertStringContainsString("DB_NAME() NOT IN (N'tc')", $sql);
        self::assertDoesNotMatchRegularExpression('/\b(DELETE|TRUNCATE|DROP)\b/i', $sql);
        self::assertSame(8, preg_match_all("/^\\s*\\((\\d), N'[^']+', N'[^']+', N'[a-z]+@[a-z]+\\.example'\\)[,;]/m", $sql), 'eight suppliers');
        foreach (['CM-2200', 'OH-004', 'sample-OH-004', 'Credit note CN-2200', 'Bank details changed', 'River-statement.pdf', 'PO-1001', 'PO-1010'] as $needle) {
            self::assertStringContainsString($needle, $sql);
        }
        self::assertSame(10, preg_match_all("/^\\s*\\((\\d+), (\\d), N'[A-Z]{2}-\\d+'/m", $sql));
        self::assertStringContainsString('WHERE NOT EXISTS', $sql);
    }

    public function test_the_sync_cutoff_alter_is_idempotent_standalone_and_says_it_is_not_applied(): void
    {
        $alter = self::script('alter-finance-ap2-sync-cutoff.sql');
        $create = self::script('create-finance-ap2-schema.sql');

        self::assertStringContainsString('NOT APPLIED', $alter);
        self::assertStringContainsString('SET QUOTED_IDENTIFIER ON', $alter);
        self::assertStringContainsString('SET ANSI_NULLS ON', $alter);
        foreach (['high_water_at', 'window_start_at', 'window_end_at', 'target_at', 'processed', 'Cancelled'] as $needle) {
            self::assertStringContainsString($needle, $alter);
            self::assertStringContainsString($needle, $create, 'a fresh build has it too');
        }
        self::assertStringNotContainsString('DELETE', $alter);
        self::assertStringNotContainsString('DROP TABLE', $alter);
    }
}
