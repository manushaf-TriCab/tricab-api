<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailLedger;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * The registry and the ledger over `create-finance-schema.sql`, against a real
 * local SQL Server ({@see LocalFinanceSqlServer}); skipped without one.
 */
#[Group('local-sql-server')]
final class AzureSqlFinanceMailTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlFinanceMailLedger $ledger;

    private AzureSqlFinanceMailboxRegistry $registry;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->ledger = new AzureSqlFinanceMailLedger($connection);
        $this->registry = new AzureSqlFinanceMailboxRegistry($connection);
    }

    private function deliveryCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM [finance].[mail_deliveries]');
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private static function delivery(string $id, ?string $key, string $app = 'TASK', ?int $actor = null): FinanceMailDelivery
    {
        return new FinanceMailDelivery(
            $id, $app, $actor, 'ap@tricab.com', ['jane@tricab.com', 'bob@tricab.com'], ['cc@tricab.com'], 'Réminder — ✓',
            hash('sha256', 'body'), ['a.pdf', 'b.xlsx'], $key, FinanceMailRules::STATUS_PENDING, null,
            new DateTimeImmutable('2026-10-07 01:02:03.456789', new DateTimeZone('UTC')), null,
        );
    }

    private function seedMailboxes(): void
    {
        $this->pdo->exec("INSERT INTO [finance].[mailboxes] ([id], [email], [display_name], [purpose], [is_active]) VALUES
            ('11111111111111111111111111111111', N'ap@tricab.com', N'Accounts Payable', N'Tri-AP intake', 1),
            ('22222222222222222222222222222222', N'old@tricab.com', N'Retired', N'', 0)");
        $this->pdo->exec("INSERT INTO [finance].[mailbox_grants] ([id], [application_key], [mailbox_id], [can_read], [can_send]) VALUES
            ('a1111111111111111111111111111111', N'AP', '11111111111111111111111111111111', 1, 1),
            ('a2222222222222222222222222222222', N'TASK', '11111111111111111111111111111111', 0, 1)");
    }

    public function test_a_pending_row_round_trips_with_unicode_and_six_digit_time(): void
    {
        self::assertTrue($this->ledger->recordPending(self::delivery('d1', 'k1', 'TASK', 42)));

        $row = $this->ledger->findByIdempotencyKey('TASK', 'k1');
        self::assertNotNull($row);
        self::assertSame('d1', $row->id);
        self::assertSame(42, $row->actorUserId);
        self::assertSame(['jane@tricab.com', 'bob@tricab.com'], $row->to);
        self::assertSame(['cc@tricab.com'], $row->cc);
        self::assertSame('Réminder — ✓', $row->subject);
        self::assertSame(['a.pdf', 'b.xlsx'], $row->attachmentNames);
        self::assertSame(FinanceMailRules::STATUS_PENDING, $row->status);
        self::assertSame('2026-10-07 01:02:03.456789', $row->createdAt->format('Y-m-d H:i:s.u'));
        self::assertNull($row->sentAt);
    }

    public function test_the_idempotency_key_is_unique_per_app_and_a_second_write_is_refused_not_thrown(): void
    {
        self::assertTrue($this->ledger->recordPending(self::delivery('d1', 'k1', 'TASK')));
        self::assertFalse($this->ledger->recordPending(self::delivery('d2', 'k1', 'TASK')));
        self::assertTrue($this->ledger->recordPending(self::delivery('d3', 'k1', 'AP')));

        self::assertSame(2, $this->deliveryCount());
        self::assertSame('d1', $this->ledger->findByIdempotencyKey('TASK', 'k1')?->id);
    }

    public function test_sends_without_a_key_never_collide(): void
    {
        self::assertTrue($this->ledger->recordPending(self::delivery('d1', null)));
        self::assertTrue($this->ledger->recordPending(self::delivery('d2', null)));

        self::assertSame(2, $this->deliveryCount());
    }

    public function test_sent_and_failed_update_the_row(): void
    {
        $this->ledger->recordPending(self::delivery('d1', 'k1'));
        $this->ledger->recordPending(self::delivery('d2', 'k2'));

        $this->ledger->markSent('d1', new DateTimeImmutable('2026-10-07 02:00:00.250000', new DateTimeZone('UTC')));
        $this->ledger->markFailed('d2', str_repeat('e', 700));

        $sent = $this->ledger->findByIdempotencyKey('TASK', 'k1');
        self::assertNotNull($sent);
        self::assertSame(FinanceMailRules::STATUS_SENT, $sent->status);
        self::assertSame('2026-10-07 02:00:00.250000', $sent->sentAt?->format('Y-m-d H:i:s.u'));
        $failed = $this->ledger->findByIdempotencyKey('TASK', 'k2');
        self::assertNotNull($failed);
        self::assertSame(FinanceMailRules::STATUS_FAILED, $failed->status);
        self::assertSame(500, strlen((string) $failed->errorMessage));
    }

    public function test_a_suppressed_row_is_reopened_once_and_only_while_it_is_suppressed(): void
    {
        $this->ledger->recordPending(self::delivery('d1', 'k1'));
        $this->ledger->markSuppressed('d1', 'Mail is switched off.');

        $suppressed = $this->ledger->findByIdempotencyKey('TASK', 'k1');
        self::assertNotNull($suppressed);
        self::assertSame(FinanceMailRules::STATUS_SUPPRESSED, $suppressed->status);
        self::assertSame('Mail is switched off.', $suppressed->errorMessage);

        self::assertTrue($this->ledger->reopenSuppressed(self::delivery('d1', 'k1', 'TASK', 7)));
        self::assertFalse($this->ledger->reopenSuppressed(self::delivery('d1', 'k1', 'TASK', 8)));

        $reopened = $this->ledger->findByIdempotencyKey('TASK', 'k1');
        self::assertNotNull($reopened);
        self::assertSame(FinanceMailRules::STATUS_PENDING, $reopened->status);
        self::assertSame(7, $reopened->actorUserId);
        self::assertNull($reopened->errorMessage);
        self::assertSame(1, $this->deliveryCount());
    }

    public function test_the_registry_finds_a_mailbox_in_any_case_with_its_grants(): void
    {
        $this->seedMailboxes();

        $mailbox = $this->registry->find('  AP@Tricab.com ');
        self::assertNotNull($mailbox);
        self::assertSame('Accounts Payable', $mailbox->displayName);
        self::assertTrue($mailbox->active);
        self::assertSame(['AP' => ['read' => true, 'send' => true], 'TASK' => ['read' => false, 'send' => true]], $mailbox->grants);
        self::assertTrue($mailbox->canSend('TASK'));
        self::assertFalse($mailbox->canRead('TASK'));
        self::assertFalse($mailbox->canSend('AR'));
        self::assertNull($this->registry->find('nobody@tricab.com'));
    }

    public function test_the_registry_lists_every_mailbox_by_address_and_an_inactive_one_cannot_send(): void
    {
        $this->seedMailboxes();

        $all = $this->registry->all();
        self::assertSame(['ap@tricab.com', 'old@tricab.com'], array_map(static fn ($m): string => $m->address, $all));
        self::assertFalse($all[1]->active);
        self::assertSame([], $all[1]->grants);
        self::assertFalse($all[1]->canSend('AP'));
    }

    public function test_the_service_over_the_real_tables_leaves_one_ledger_row_per_key_whatever_happens(): void
    {
        $this->seedMailboxes();
        $service = new FinanceMailService($this->registry, $this->ledger, new RecordingFinanceMailTransport());
        $message = new FinanceMailMessage('TASK', 'ap@tricab.com', ['jane@tricab.com'], 'Hi', '<p>x</p>', idempotencyKey: 'real-1');

        $first = $service->send($message);
        $again = $service->send($message);

        self::assertFalse($first->deduplicated);
        self::assertTrue($again->deduplicated);
        self::assertSame(1, $this->deliveryCount());
        self::assertSame(FinanceMailRules::STATUS_SENT, $this->ledger->findByIdempotencyKey('TASK', 'real-1')?->status);

        $failing = new RecordingFinanceMailTransport();
        $failing->failWith = new FinanceMailTransportFailed('nope');
        try {
            (new FinanceMailService($this->registry, $this->ledger, $failing))
                ->send(new FinanceMailMessage('TASK', 'ap@tricab.com', ['jane@tricab.com'], 'Hi', 'x', idempotencyKey: 'real-2'));
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed) {
            self::assertSame(FinanceMailRules::STATUS_FAILED, $this->ledger->findByIdempotencyKey('TASK', 'real-2')?->status);
        }
    }
}
