<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Npr\Mail\AzureSqlNprMailOutbox;
use TriCab\Api\Npr\Persistence\AzureSqlNprRequestRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Domain\NprTransition;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Mail\NewNprOutboxEntry;
use TriCab\Core\Npr\Mail\NprMailMode;
use TriCab\Core\Npr\Mail\NprNotificationPlanner;
use TriCab\Core\Npr\Mail\OutboxNprEventSink;

/** The outbox rows join the transition's SQL transaction: written inside it, rolled back with it. */
final class AzureSqlNprOutboxTest extends TestCase
{
    private const ID = '5e8f0a3c-1b2d-4c3e-8f4a-0123456789ab';

    private FakeSqlConnection $fake;

    private FailingSqlConnection $connection;

    protected function setUp(): void
    {
        $this->fake = new FakeSqlConnection();
        $fake = $this->fake;
        $this->connection = new FailingSqlConnection($fake);
    }

    private function repository(): AzureSqlNprRequestRepository
    {
        $clock = FixedClock::utc('2026-10-19 03:00:00');

        return new AzureSqlNprRequestRepository(
            $this->connection,
            $clock,
            new OutboxNprEventSink(
                new NprNotificationPlanner('tsdqueue@example.test', 'deputy@example.test'),
                new AzureSqlNprMailOutbox($this->connection, $clock),
                NprMailMode::Live,
            ),
        );
    }

    /** @return array<string, mixed> */
    private function row(string $status): array
    {
        return [
            'PublicId' => strtoupper(self::ID), 'RequestNumber' => 'NPR-2026-0007', 'CurrentRevision' => 1, 'Status' => $status,
            'ScreeningOutcome' => null, 'Title' => 'Cable', 'CustomerName' => 'BHP', 'UrgencyLevel' => 'standard',
            'RequesterUserId' => 10, 'RequesterEmail' => 'amandaw@tricab.com', 'RequesterName' => 'Amanda', 'RequesterBranch' => 'Brisbane',
            'AssignedManagerUserId' => 30, 'AssignedManagerEmail' => 'shanep@tricab.com', 'AssignedManagerName' => 'Shane',
            'CreatedAt' => '2026-10-19 02:00:00.000', 'UpdatedAt' => '2026-10-19 03:00:00.000', 'SubmittedAt' => null,
            'Version' => '0x00000000000007D2', 'AnswersJson' => '{}', 'VariantsJson' => '[]',
        ];
    }

    private function endorse(): NprTransition
    {
        return new NprTransition(
            'manager_endorsed',
            'manager',
            new NprPerson(30, 'shanep@tricab.com', 'Shane'),
            [RequestStatus::AwaitingManager],
            RequestStatus::WithTsd,
            'Endorsed',
            markEndorsed: true,
            event: NprWorkflowEvent::MANAGER_ENDORSED,
            firstTsdHandoff: true,
        );
    }

    private function queueTransition(): void
    {
        $this->fake->queueFetch([['RequestId' => 55, 'Status' => 'awaiting_manager', 'CurrentRevision' => 1, 'Version' => '0x00000000000007D1']]);
        $this->fake->queueFetch([['ActivityId' => 77]]);
        $this->fake->queueFetch([$this->row('with_tsd')]);
        $this->fake->queueFetch([['OutboxId' => 9]]);
    }

    public function test_the_outbox_rows_are_written_inside_the_transitions_transaction(): void
    {
        $this->queueTransition();
        $this->fake->queueFetch([$this->row('with_tsd')]);

        $this->repository()->transition(self::ID, '0x00000000000007D1', $this->endorse());

        self::assertTrue($this->fake->beganTransaction);
        self::assertTrue($this->fake->committed);
        self::assertFalse($this->fake->rolledBack);
        $sql = array_column($this->fake->calls, 'sql');
        $find = static fn (string $needle): int => (int) array_key_first(array_filter($sql, static fn (string $s): bool => str_contains($s, $needle)));
        self::assertGreaterThan($find('INSERT INTO [npr].[Activity]'), $find('INSERT INTO [npr].[NotificationOutbox]'), 'outbox after the Activity row');
        self::assertGreaterThan($find('UPDATE [npr].[Requests]'), $find('INSERT INTO [npr].[NotificationOutbox]'));

        $outbox = $this->fake->calls[$find('INSERT INTO [npr].[NotificationOutbox]')];
        self::assertSame(77, $outbox['params']['activityId'], 'tied to the transition\'s Activity row');
        self::assertSame(55, $outbox['params']['requestId']);
        self::assertSame('manager_endorsed', $outbox['params']['event']);
        self::assertSame('pending', $outbox['params']['status']);
        self::assertSame('["tsdqueue@example.test"]', $outbox['params']['toJson']);
        self::assertSame('["amandaw@tricab.com","shanep@tricab.com","deputy@example.test"]', $outbox['params']['ccJson']);
        $deliveries = array_filter($sql, static fn (string $s): bool => str_contains($s, 'INSERT INTO [npr].[NotificationDeliveries]'));
        self::assertCount(4, $deliveries, 'one per address');
    }

    public function test_a_failed_outbox_write_rolls_the_whole_transition_back(): void
    {
        $this->queueTransition();
        $this->connection->failOn = 'INSERT INTO [npr].[NotificationOutbox]';

        try {
            $this->repository()->transition(self::ID, '0x00000000000007D1', $this->endorse());
            self::fail('expected the outbox failure to propagate');
        } catch (RuntimeException $failure) {
            self::assertSame('simulated failure', $failure->getMessage());
        }

        self::assertTrue($this->fake->rolledBack);
        self::assertFalse($this->fake->committed, 'nothing of the transition is committed');
        $sql = implode("\n", array_column($this->fake->calls, 'sql'));
        self::assertStringContainsString('UPDATE [npr].[Requests]', $sql, 'the header was written, inside the transaction that rolled back');
        self::assertStringContainsString('INSERT INTO [npr].[Activity]', $sql);
    }

    public function test_a_missing_recipient_is_written_as_a_failed_delivery_row(): void
    {
        $clock = FixedClock::utc('2026-10-19 03:00:00');
        $outbox = new AzureSqlNprMailOutbox($this->connection, $clock);
        $this->fake->queueFetch([['OutboxId' => 3]]);

        $outbox->enqueue(new NewNprOutboxEntry('manager_endorsed', self::ID, [], ['a@x.test'], [['role' => 'tsd_queue', 'type' => 'to']], ['requestNumber' => 'N'], 'pending'), 5, 6);

        $rows = array_values(array_filter($this->fake->calls, static fn (array $c): bool => str_contains($c['sql'], 'NotificationDeliveries')));
        self::assertCount(2, $rows);
        self::assertSame('a@x.test', $rows[0]['params']['email']);
        self::assertSame('(missing: tsd_queue)', $rows[1]['params']['email']);
        self::assertSame('failed', $rows[1]['params']['status']);
        self::assertNotEmpty($rows[1]['params']['error']);
        self::assertTrue($this->fake->committed);
    }

    public function test_an_attempt_leaves_the_missing_recipient_rows_alone(): void
    {
        $outbox = new AzureSqlNprMailOutbox($this->connection, FixedClock::utc('2026-10-19 03:00:00'));

        $outbox->recordAttempt(3, true, null, new \DateTimeImmutable('2026-10-19 03:00:00'));

        $delivery = $this->fake->calls[1];
        self::assertStringContainsString('UPDATE [npr].[NotificationDeliveries]', $delivery['sql']);
        self::assertStringContainsString("NOT LIKE N'(missing: %'", $delivery['sql']);
        self::assertSame('sent', $delivery['params']['status']);
    }
}

final class FailingSqlConnection implements SqlConnection
{
    public ?string $failOn = null;

    public function __construct(private readonly FakeSqlConnection $inner)
    {
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->maybeFail($sql);

        return $this->inner->fetchAll($sql, $params);
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->maybeFail($sql);
        $this->inner->execute($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    private function maybeFail(string $sql): void
    {
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            throw new RuntimeException('simulated failure');
        }
    }
}
