<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Tests\Finance\Ar\LocalArIdentityServer;

/**
 * The three scripts written for the Tri-Task and Tri-AR go-live (8 Oct 2026), run for real on a disposable local
 * SQL Server database: the production mailbox registry, the role assignments and the Tri-AR data reset. Skipped
 * without a local server. Every person here is invented except the addresses the scripts themselves name, which get
 * no platform user unless a test makes one.
 */
#[Group('local-sql-server')]
final class FinanceProductionScriptsTest extends TestCase
{
    private const SETUP = [
        'seed-finance-task-permissions.sql',
        'seed-finance-task-roles.sql',
        'create-finance-schema.sql',
        'create-finance-attachments-schema.sql',
        'create-finance-ar-schema.sql',
    ];

    private PDO $pdo;

    protected function setUp(): void
    {
        $pdo = LocalArIdentityServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalArIdentityServer::reset($pdo);
        foreach (self::SETUP as $file) {
            $this->sql(self::script($file));
        }
        $this->sql("DELETE FROM [finance].[mailbox_grants]; DELETE FROM [finance].[mailboxes];");
        $this->sql(str_replace("@Confirm NVARCHAR(40) = N''", "@Confirm NVARCHAR(40) = N'DELETE TRI-AR DATA'", self::script('reset-finance-ar-data.sql')));
    }

    private static function script(string $file): string
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/database/scripts/development/' . $file);
        if ($script === false) {
            throw new RuntimeException($file . ' is missing.');
        }

        return $script;
    }

    /** Runs every batch and walks every result set, so an error raised after the first one is not lost. */
    private function sql(string $sql): void
    {
        foreach (preg_split('/^\s*GO\s*$/mi', $sql) ?: [] as $batch) {
            if (trim($batch) === '') {
                continue;
            }
            $statement = $this->pdo->query($batch);
            self::assertInstanceOf(PDOStatement::class, $statement);
            do {
                if ($statement->columnCount() > 0) {
                    $statement->fetchAll();
                }
            } while ($statement->nextRowset());
        }
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private function user(string $email): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
             SELECT :email, :normalized, :name, [UserStatusId] FROM [identity].[UserStatuses] WHERE [StatusCode] = N\'active\'',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['email' => $email, 'normalized' => strtolower($email), 'name' => 'Test Person']);
    }

    /** @return list<string> */
    private function rolesOf(string $email): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.[RoleCode] FROM [identity].[UserRoles] ur
             JOIN [identity].[Users] u ON u.[UserId] = ur.[UserId]
             JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
             WHERE u.[NormalizedEmailAddress] = :email AND ur.[IsActive] = 1 ORDER BY r.[RoleCode]',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['email' => $email]);

        $roles = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $code) {
            self::assertIsString($code);
            $roles[] = $code;
        }

        return $roles;
    }

    public function test_the_mailbox_registry_lands_once_and_a_second_run_changes_nothing(): void
    {
        $this->sql(self::script('seed-finance-mailboxes-production.sql'));
        $this->sql(self::script('seed-finance-mailboxes-production.sql'));

        self::assertSame(6, $this->scalar('SELECT COUNT(*) FROM [finance].[mailboxes] WHERE [is_active] = 1'));
        self::assertSame(6, $this->scalar('SELECT COUNT(*) FROM [finance].[mailbox_grants]'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[mailbox_grants] g JOIN [finance].[mailboxes] m ON m.[id] = g.[mailbox_id] WHERE m.[email] = N'ar@tricab.com' AND g.[application_key] = N'AR' AND g.[can_read] = 1 AND g.[can_send] = 1"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[mailbox_grants] g JOIN [finance].[mailboxes] m ON m.[id] = g.[mailbox_id] WHERE m.[email] = N'financeau@tricab.com' AND g.[application_key] = N'TASK' AND g.[can_read] = 0 AND g.[can_send] = 1"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[mailbox_grants] WHERE [application_key] IN (N'CREDIT', N'COLLECT')"), 'the deferred apps get no grant');
    }

    public function test_a_grant_that_was_narrower_is_set_to_the_registry(): void
    {
        $this->sql(self::script('seed-finance-mailboxes-production.sql'));
        $this->sql("UPDATE g SET g.[can_send] = 0 FROM [finance].[mailbox_grants] g JOIN [finance].[mailboxes] m ON m.[id] = g.[mailbox_id] WHERE m.[email] = N'ar@tricab.com'");

        $this->sql(self::script('seed-finance-mailboxes-production.sql'));

        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[mailbox_grants] g JOIN [finance].[mailboxes] m ON m.[id] = g.[mailbox_id] WHERE m.[email] = N'ar@tricab.com' AND g.[can_send] = 1"));
    }

    public function test_roles_go_to_the_people_who_exist_and_nobody_is_created(): void
    {
        $this->user('manushaf@tricab.com');
        $this->user('linx@tricab.com');
        $this->user('olivern@tricab.com');
        $this->user('bystander@example.test');

        $this->sql(self::script('assign-finance-roles-production.sql'));
        $this->sql(self::script('assign-finance-roles-production.sql'));

        self::assertSame(['FINANCE_ADMIN', 'FINANCE_AR_ADMIN', 'FINANCE_TASK_ADMIN'], $this->rolesOf('manushaf@tricab.com'));
        self::assertSame(['FINANCE_AR_REVIEWER', 'FINANCE_TASK_USER'], $this->rolesOf('linx@tricab.com'));
        self::assertSame(['FINANCE_TASK_USER'], $this->rolesOf('olivern@tricab.com'));
        self::assertSame([], $this->rolesOf('bystander@example.test'));
        self::assertSame(4, $this->scalar('SELECT COUNT(*) FROM [identity].[Users]'), 'a person with no platform user is skipped, not created');
        self::assertSame(6, $this->scalar('SELECT COUNT(*) FROM [identity].[UserRoles]'), 'a second run adds nothing');
    }

    public function test_an_assignment_that_was_switched_off_is_switched_on_again(): void
    {
        $this->user('mingl@tricab.com');
        $this->sql(self::script('assign-finance-roles-production.sql'));
        $this->sql('UPDATE [identity].[UserRoles] SET [IsActive] = 0');

        $this->sql(self::script('assign-finance-roles-production.sql'));

        self::assertSame(['FINANCE_AR_REVIEWER', 'FINANCE_TASK_USER'], $this->rolesOf('mingl@tricab.com'));
    }

    public function test_the_reset_only_counts_until_it_is_confirmed_and_then_empties_tri_ar_alone(): void
    {
        $this->sql("INSERT INTO [finance].[ar_option_lists] ([list_name], [value], [label], [sort_order]) VALUES (N'category', N'other', N'Other', 1)");
        $this->sql("INSERT INTO [finance].[attachments] ([id], [application_key], [blob_path], [original_name], [content_type], [byte_size], [sha256]) VALUES ('000000000000000000000000000000a1', N'AR', N'ar/x.pdf', N'x.pdf', N'application/pdf', 1, REPLICATE('a', 64)), ('000000000000000000000000000000a2', N'TASK', N'task/y.pdf', N'y.pdf', N'application/pdf', 1, REPLICATE('b', 64))");

        $this->sql(self::script('reset-finance-ar-data.sql'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_option_lists]'), 'as written it deletes nothing');

        $this->sql(str_replace("@Confirm NVARCHAR(40) = N''", "@Confirm NVARCHAR(40) = N'DELETE TRI-AR DATA'", self::script('reset-finance-ar-data.sql')));

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_option_lists]'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[attachments] WHERE [application_key] = N'AR'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[attachments] WHERE [application_key] = N'TASK'"), 'another app\'s file is not touched');
        $this->sql("DELETE FROM [finance].[attachments] WHERE [application_key] = N'TASK'");
    }
}
