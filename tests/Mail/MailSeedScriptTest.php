<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Mail\Module;

/** The mail seed scripts say what `Module` says, and the local rebuild runs them in the right order. */
final class MailSeedScriptTest extends TestCase
{
    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_permission_seed_creates_exactly_the_module_permissions(): void
    {
        preg_match_all("/^\\s*\\(N'(MAIL\\.[A-Z.]+)',/m", self::script('seed-mail-permissions.sql'), $matches);
        $seeded = $matches[1];
        sort($seeded);
        $expected = Module::PERMISSIONS;
        sort($expected);

        self::assertSame($expected, $seeded);
    }

    public function test_the_role_seed_grants_exactly_the_module_role_permissions(): void
    {
        preg_match_all("/\\(N'(RFQ_[A-Z_]+)', N'(MAIL\\.[A-Z.]+)'\\)/", self::script('seed-mail-roles.sql'), $matches, PREG_SET_ORDER);
        $seeded = [];
        foreach ($matches as $match) {
            $seeded[$match[1]][] = $match[2];
        }

        self::assertSame(Module::ROLE_PERMISSIONS, $seeded);
        // It grants to roles that exist and creates none.
        self::assertDoesNotMatchRegularExpression('/INSERT INTO \[identity\]\.\[Roles\]/', self::script('seed-mail-roles.sql'));
    }

    public function test_employees_and_guests_are_never_granted_mail(): void
    {
        self::assertDoesNotMatchRegularExpression("/\\(N'(EMPLOYEE|GUEST)',\\s*N'MAIL\\./", self::script('seed-mail-permissions.sql'));
        self::assertDoesNotMatchRegularExpression("/\\(N'(EMPLOYEE|GUEST)',\\s*N'MAIL\\./", self::script('seed-mail-roles.sql'));
    }

    public function test_the_schema_and_both_seeds_are_part_of_the_local_rebuild_in_order(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');
        foreach (['create-mail-schema.sql', 'seed-mail-permissions.sql', 'seed-mail-roles.sql'] as $script) {
            self::assertStringContainsString("'" . $script . "'", $rebuild);
        }
        self::assertLessThan(strpos($rebuild, "'seed-mail-roles.sql'"), strpos($rebuild, "'seed-mail-permissions.sql'"));
        self::assertLessThan(strpos($rebuild, "'seed-mail-roles.sql'"), strpos($rebuild, "'seed-rfq-roles.sql'"));
    }

    public function test_the_schema_is_idempotent_and_keeps_to_its_own_schema(): void
    {
        $sql = self::script('create-mail-schema.sql');

        preg_match_all('/CREATE TABLE \[(\w+)\]\.\[(\w+)\]/', $sql, $tables, PREG_SET_ORDER);
        self::assertCount(8, $tables);
        foreach ($tables as $table) {
            self::assertSame('mail', $table[1]);
            self::assertStringContainsString("IF OBJECT_ID(N'[mail].[" . $table[2] . "]', N'U') IS NULL", $sql);
        }
        self::assertStringContainsString("IF SCHEMA_ID(N'mail') IS NULL", $sql);
        // No foreign key leaves [mail]: a mail-only restore stays possible.
        preg_match_all('/REFERENCES \[(\w+)\]\./', $sql, $references);
        self::assertSame(['mail'], array_values(array_unique($references[1])));
        // The dedupe key (RFQ plan 3.3).
        self::assertStringContainsString('UX_mail_messages_internet_id', $sql);
        // Mail content is not stored: no body column anywhere.
        self::assertDoesNotMatchRegularExpression('/\[(body|body_html|body_text|content_bytes)\]/', $sql);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private static function runSampleSeed(array $args, string $host): array
    {
        $script = dirname(__DIR__, 2) . '/bin/seed-local-mail.php';
        $process = proc_open(
            array_merge([PHP_BINARY, $script], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['DB_HOST' => $host, 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($process), $output];
    }

    public function test_the_local_sample_seed_refuses_a_database_that_is_not_this_machine(): void
    {
        [$code, $output] = self::runSampleSeed(['--yes'], 'tricab-mel.database.windows.net');

        self::assertSame(1, $code);
        self::assertStringContainsString('Refusing', $output);
    }

    public function test_the_local_sample_seed_wants_an_explicit_yes(): void
    {
        [$code, $output] = self::runSampleSeed([], 'localhost');

        self::assertSame(1, $code);
        self::assertStringContainsString('--yes', $output);
    }
}
