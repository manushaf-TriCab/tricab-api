<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar\Migration;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;

/**
 * `bin/migrate-finance-ar.php` as Manusha will run it: a real PHP process, with every setting it relies on stated so
 * a developer's own `.env` cannot change what is proved (the script loads `.env` for anything not stated; nothing
 * here is left to it that the script reads: the database, the blob mode, the module switches that would register
 * a job or reach another system). The refusals are decided before any database is touched, so those cases need no
 * database at all. The plan and the refused apply run against the disposable local SQL Server.
 */
final class ArMigrationScriptTest extends TestCase
{
    private InventedTriArData $data;

    protected function setUp(): void
    {
        $this->data = new InventedTriArData();
    }

    /**
     * @param array<string, string> $over
     * @return array{int, string, string}
     */
    private function runScript(array $over, string ...$args): array
    {
        $env = [
            'APP_ENV' => 'development',
            'APP_NAME' => 'tricab-api',
            'DEV_AUTH_ENABLED' => 'true',
            'USER_REPOSITORY' => 'azure-sql',
            'DB_AUTH_MODE' => 'sql-login',
            'DB_DRIVER' => 'sqlsrv',
            'DB_HOST' => 'localhost',
            'DB_PORT' => '1433',
            'DB_DATABASE' => LocalFinanceSqlServer::DATABASE,
            'DB_USERNAME' => 'sa',
            'DB_PASSWORD' => 'not-set',
            'DB_ENCRYPT' => 'true',
            'DB_TRUST_SERVER_CERTIFICATE' => 'true',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'GUEST_JWT_TTL_SECONDS' => '86400',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'PASSKEY_FAKE_CEREMONY' => 'true',
            'APP_CACHE_DRIVER' => 'array',
            'APP_PERFORMANCE_LOGGING' => 'false',
            'BLOB_AUTH_MODE' => 'memory',
            'BLOB_CONTAINER_FINANCE' => 'finance-attachments',
            // Nothing that registers a job, sends mail, reads a mailbox or calls Odoo or a model: all off, and the
            // credentials blank (a single space, because Windows drops a variable set to the empty string).
            'FINANCE_MODULE_ENABLED' => 'false',
            'FINANCE_TASK_ENABLED' => 'false',
            'FINANCE_AR_ENABLED' => 'false',
            'FINANCE_AR_SENDING_ENABLED' => 'false',
            'FINANCE_AR_SAMPLE_FILES' => 'false',
            'FINANCE_MAILBOX_READ_ENABLED' => 'false',
            'FINANCE_AI_ENABLED' => 'false',
            'FINANCE_MAIL_ENABLED' => 'false',
            'FINANCE_GRAPH_TENANT_ID' => ' ',
            'FINANCE_GRAPH_CLIENT_ID' => ' ',
            'FINANCE_GRAPH_CLIENT_SECRET' => ' ',
            'ODOO_URL' => ' ',
            'ODOO_API_KEY' => ' ',
        ] + array_intersect_key(array_filter(getenv(), 'is_string'), array_flip(['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'TEMP', 'TMP', 'windir', 'WINDIR', 'HOME', 'SystemDrive', 'ComSpec', 'ProgramData', 'ALLUSERSPROFILE', 'ProgramFiles', 'ProgramFiles(x86)', 'CommonProgramFiles', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA']));
        foreach ($over as $key => $value) {
            $env[$key] = $value;
        }

        $process = proc_open(
            array_merge([PHP_BINARY, dirname(__DIR__, 4) . '/bin/migrate-finance-ar.php'], array_values($args)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4),
            $env,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** @return list<string> */
    private function source(): array
    {
        return ['--source=' . $this->data->database, '--attachments=' . $this->data->attachments];
    }

    public function test_without_a_source_and_a_folder_it_prints_the_usage_and_exits_two(): void
    {
        [$status, $out, $err] = $this->runScript([]);

        self::assertSame(2, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('Usage: php bin/migrate-finance-ar.php', $err);
    }

    public function test_a_missing_source_file_is_refused_and_not_created(): void
    {
        $missing = $this->data->dir . DIRECTORY_SEPARATOR . 'nothing.sqlite3';

        [$status, , $err] = $this->runScript([], '--source=' . $missing, '--attachments=' . $this->data->attachments);

        self::assertSame(2, $status);
        self::assertStringContainsString('does not exist', $err);
        self::assertFileDoesNotExist($missing);
    }

    public function test_the_two_message_id_options_together_are_a_usage_error(): void
    {
        [$status, , $err] = $this->runScript([], ...$this->source(), ...['--translate-ids', '--without-message-ids']);

        self::assertSame(2, $status);
        self::assertStringContainsString('contradict', $err);
    }

    public function test_translate_ids_is_refused_without_the_finance_graph_settings(): void
    {
        // The settings are stated blank here (see the environment above), so nothing reaches Graph.
        [$status, $out, $err] = $this->runScript([], ...$this->source(), ...['--translate-ids']);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('FINANCE_GRAPH_TENANT_ID', $err);
    }

    public function test_apply_against_a_database_that_is_not_this_machine_needs_an_approval(): void
    {
        // The blob settings are stated, not left to a developer's `.env`: without them the settings themselves refuse
        // `connection-string` and the script dies before it can answer (as it did on a machine with no `.env`).
        $blob = ['BLOB_AUTH_MODE' => 'connection-string', 'BLOB_ACCOUNT_NAME' => 'devaccount', 'BLOB_ACCOUNT_KEY' => 'ZGV2a2V5', 'BLOB_ENDPOINT' => 'https://devaccount.blob.example.test'];
        foreach (['tricab-mel.database.windows.net', 'tricab-mel.example.test,1433', '10.0.0.5'] as $host) {
            [$status, $out, $err] = $this->runScript(['DB_HOST' => $host] + $blob, ...$this->source(), ...['--without-message-ids', '--apply']);

            self::assertSame(1, $status, $host);
            self::assertSame('', $out);
            self::assertStringContainsString('owner\'s approval', $err);
            self::assertStringContainsString('--approved-by', $err);
        }
    }

    public function test_an_approval_alone_does_not_get_past_a_file_store_that_would_lose_the_files(): void
    {
        [$status, $out, $err] = $this->runScript(['DB_HOST' => 'tricab-mel.database.windows.net'], ...$this->source(), ...['--without-message-ids', '--apply', '--approved-by=Someone Else']);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('blob container is not configured', $err);
    }

    public function test_apply_without_a_decision_about_message_ids_is_refused_before_anything_else(): void
    {
        [$status, $out, $err] = $this->runScript([], ...$this->source(), ...['--apply']);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('--without-message-ids', $err);
    }

    public function test_apply_with_the_in_memory_blob_store_is_refused_even_on_this_machine(): void
    {
        [$status, , $err] = $this->runScript(['BLOB_AUTH_MODE' => 'memory'], ...$this->source(), ...['--without-message-ids', '--apply']);

        self::assertSame(1, $status);
        self::assertStringContainsString('lost when the tool exits', $err);
    }

    public function test_without_the_sql_user_repository_it_has_no_target_and_refuses(): void
    {
        [$status, , $err] = $this->runScript(['USER_REPOSITORY' => 'memory'], ...$this->source(), ...['--without-message-ids']);

        self::assertSame(1, $status);
        self::assertStringContainsString('USER_REPOSITORY=azure-sql', $err);
    }

    /** @return array<string, string> */
    private function localDatabase(): array
    {
        $server = getenv('FINANCE_TEST_SQLSERVER') ?: '';
        $password = getenv('FINANCE_TEST_SQLSERVER_PASSWORD') ?: '';
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null || $server === '' || $password === '') {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        LocalFinanceSqlServer::truncate($pdo);
        $parts = explode(',', $server);

        return [
            // The platform's own factory dials "tcp:<host>", and "tcp:localhost" does not resolve on every machine.
            'DB_HOST' => strtolower(trim($parts[0])) === 'localhost' ? '127.0.0.1' : trim($parts[0]),
            'DB_PORT' => $parts[1] ?? '1433',
            'DB_USERNAME' => getenv('FINANCE_TEST_SQLSERVER_USER') ?: 'sa',
            'DB_PASSWORD' => $password,
        ];
    }

    /** A number out of the report's JSON line. */
    private static function number(mixed $report, string $section, string $key): int
    {
        self::assertIsArray($report);
        self::assertIsArray($report[$section] ?? null);
        $value = $report[$section][$key] ?? null;
        self::assertIsInt($value);

        return $value;
    }

    private function rows(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    #[Group('local-sql-server')]
    public function test_a_plan_reads_the_source_and_the_target_prints_counts_only_and_writes_nothing(): void
    {
        $db = $this->localDatabase();
        $record = $this->data->record(['subject' => 'Quokka subject', 'customer_name' => 'Quokka Traders']);
        $this->data->attachment($record, ['file_name' => 'quokka-remittance.pdf']);
        $this->data->record(['status' => 'bogus']);
        $this->data->scanRun();
        $pdo = LocalFinanceSqlServer::connect();
        self::assertNotNull($pdo);

        [$status, $out, $err] = $this->runScript($db, ...$this->source(), ...['--without-message-ids']);

        self::assertSame(0, $status, $err);
        $lines = explode("\n", trim($out));
        $header = json_decode($lines[0], true);
        $report = json_decode($lines[1], true);
        self::assertIsArray($header);
        self::assertSame('plan', $header['mode']);
        self::assertSame('local', $header['database']);
        self::assertSame(2, self::number($report, 'source_rows', 'email_records'));
        self::assertSame(1, self::number($report, 'to_insert', 'email_records'));
        self::assertSame(1, self::number($report, 'to_insert', 'email_attachments'));
        self::assertSame(1, self::number($report, 'to_insert', 'scan_runs'));
        self::assertSame(1, self::number($report, 'problems', 'email_records.status_not_allowed'));
        self::assertStringContainsString('PROBLEM    email_records', $out);
        foreach (['Quokka', 'quokka', 'example.test', 'Acme', 'AAMkAD', '.pdf'] as $content) {
            self::assertStringNotContainsString($content, $out);
            self::assertStringNotContainsString($content, $err);
        }
        foreach (['ar_email_records', 'ar_email_attachments', 'ar_remittance_lines', 'ar_scan_runs', 'ar_option_lists', 'ar_deleted_messages', 'attachments'] as $table) {
            self::assertSame(0, $this->rows($pdo, "SELECT COUNT(*) FROM [finance].[{$table}]"), $table);
        }
    }

    #[Group('local-sql-server')]
    public function test_a_plan_says_what_would_make_apply_refuse_and_an_apply_that_is_refused_writes_nothing(): void
    {
        $db = $this->localDatabase();
        $record = $this->data->record();
        $this->data->outbound($record);
        $pdo = LocalFinanceSqlServer::connect();
        self::assertNotNull($pdo);
        // A real-looking connection string setting that is never used: the refusal comes first.
        $blob = ['BLOB_AUTH_MODE' => 'connection-string', 'BLOB_ACCOUNT_NAME' => 'devaccount', 'BLOB_ACCOUNT_KEY' => 'ZGV2a2V5', 'BLOB_ENDPOINT' => 'https://devaccount.blob.example.test'];

        [$planStatus, $planOut] = $this->runScript($db + $blob, ...$this->source(), ...['--without-message-ids']);
        [$status, $out, $err] = $this->runScript($db + $blob, ...$this->source(), ...['--without-message-ids', '--apply']);

        self::assertSame(0, $planStatus);
        self::assertStringContainsString('WOULD REFUSE --apply  outbound_messages has 1 row(s)', $planOut);
        self::assertSame(1, $status, $err);
        self::assertStringContainsString('REFUSED    outbound_messages has 1 row(s)', $out);
        $header = json_decode(explode("\n", trim($out))[0], true);
        self::assertIsArray($header);
        self::assertTrue($header['refused']);
        self::assertSame(0, $this->rows($pdo, 'SELECT COUNT(*) FROM [finance].[ar_email_records]'));
        self::assertSame(0, $this->rows($pdo, 'SELECT COUNT(*) FROM [finance].[attachments]'));
    }
}
