<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\DTO\RfqDetail;
use TriCab\Core\Rfq\Application\DTO\RfqQueueQuery;
use TriCab\Core\Rfq\Application\DTO\RfqRecord;
use TriCab\Core\Rfq\Application\DTO\RfqSummary;
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Domain\ValueObject\IntakeChannel;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\RfqOwnership;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;

/**
 * The service between the endpoints and the store: the permission on every
 * record, and a loud failure when a repository returns one it should not have.
 */
final class ReadRfqQueueServiceTest extends TestCase
{
    private const MINE = '11111111-1111-4111-8111-111111111111';
    private const OTHER_BRANCH = '22222222-2222-4222-8222-222222222222';

    public static function record(string $publicId, ?int $owner, ?string $branch, string $priority = 'normal', string $received = '2026-09-20T00:00:00Z'): RfqRecord
    {
        $at = new DateTimeImmutable($received, new DateTimeZone('UTC'));

        return new RfqRecord(
            $publicId,
            'RFQ-' . substr($publicId, 0, 4),
            'Synthetic schedule',
            'CUST01',
            'Synthetic Customer Pty Ltd',
            new RfqOwnership($owner, null, $branch, false),
            'Owner Name',
            null,
            WorkflowStatus::new(),
            Priority::fromString($priority),
            null,
            IntakeChannel::web(),
            ExtractionState::complete(),
            null,
            null,
            3,
            1,
            $at,
            $at,
        );
    }

    private static function sales(?string $branch = null): RfqActor
    {
        return new RfqActor(10, 'u10', Module::ROLE_PERMISSIONS[Module::ROLE_SALES], $branch);
    }

    public function test_a_reader_gets_every_rfq_and_the_branches_to_filter_by(): void
    {
        $repository = (new InMemoryRfqRepository())
            ->withRecord(self::record(self::MINE, 10, 'VIC'))
            ->withRecord(self::record(self::OTHER_BRANCH, 20, 'NSW'))
            ->withRecord(self::record('33333333-3333-4333-8333-333333333333', null, null));
        $service = new ReadRfqQueueService($repository);

        $page = $service->page(self::sales('VIC'), RfqQueueQuery::defaults());
        self::assertSame(3, $page->total);
        self::assertSame(['NSW', 'VIC'], $page->toArray()['branches']);

        $nsw = $service->page(self::sales('VIC'), RfqQueueQuery::fromQueryParams(['branch' => 'nsw']));
        self::assertSame([self::OTHER_BRANCH], array_map(static fn (RfqSummary $s): string => $s->record->publicId, $nsw->items));
        $none = $service->page(self::sales('VIC'), RfqQueueQuery::fromQueryParams(['branch' => 'none,VIC']));
        self::assertSame(2, $none->total, 'none is the RFQs with no branch');
    }

    public function test_without_read_an_rfq_and_an_unknown_id_are_the_same_answer(): void
    {
        $repository = (new InMemoryRfqRepository())->withRecord(self::record(self::OTHER_BRANCH, 20, 'NSW'));
        $service = new ReadRfqQueueService($repository);
        $noRead = new RfqActor(10, 'u10', [Module::PERMISSION_ACCESS], null);

        self::assertNotNull($service->detail(self::sales('VIC'), self::OTHER_BRANCH), 'another branch is visible');
        self::assertNull($service->detail($noRead, self::OTHER_BRANCH));
        self::assertNull($service->detail(self::sales('VIC'), '33333333-3333-4333-8333-333333333333'));
        self::assertNull($service->detail(self::sales('VIC'), 'not-a-uuid'));
        self::assertSame([], $service->page($noRead, RfqQueueQuery::defaults())->toArray()['branches']);
    }

    public function test_an_id_is_matched_case_insensitively(): void
    {
        $repository = (new InMemoryRfqRepository())->withRecord(self::record(self::MINE, 10, 'VIC'));

        self::assertNotNull((new ReadRfqQueueService($repository))->detail(self::sales('VIC'), strtoupper(self::MINE)));
    }

    /** A repository that returns RFQs to somebody who may not read them must not pass quietly. */
    public function test_a_repository_that_leaks_fails_loudly(): void
    {
        $leaky = new class implements RfqRepository {
            public function pageVisibleTo(RfqActor $actor, RfqQueueQuery $query): array
            {
                return ['items' => [ReadRfqQueueServiceTest::record('22222222-2222-4222-8222-222222222222', 20, 'NSW')], 'total' => 1];
            }

            public function findRecord(string $publicId): ?RfqRecord
            {
                return null;
            }

            public function activity(string $publicId, int $limit): array
            {
                return [];
            }

            public function branches(): array
            {
                return [];
            }

            public function findDetail(RfqRecord $record, array $allowedActions): RfqDetail
            {
                return new RfqDetail(new RfqSummary($record, $allowedActions), null, [], [], []);
            }
        };

        $this->expectException(LogicException::class);
        (new ReadRfqQueueService($leaky))->page(new RfqActor(10, 'u10', [Module::PERMISSION_ACCESS], null), RfqQueueQuery::defaults());
    }

    public function test_the_queue_orders_by_priority_then_newest(): void
    {
        $repository = (new InMemoryRfqRepository())
            ->withRecord(self::record('aaaaaaaa-0000-4000-8000-000000000001', 10, 'VIC', 'low', '2026-09-25T00:00:00Z'))
            ->withRecord(self::record('aaaaaaaa-0000-4000-8000-000000000002', 10, 'VIC', 'urgent', '2026-09-01T00:00:00Z'))
            ->withRecord(self::record('aaaaaaaa-0000-4000-8000-000000000003', 10, 'VIC', 'normal', '2026-09-24T00:00:00Z'))
            ->withRecord(self::record('aaaaaaaa-0000-4000-8000-000000000004', 10, 'VIC', 'normal', '2026-09-26T00:00:00Z'));

        $page = (new ReadRfqQueueService($repository))->page(self::sales(), RfqQueueQuery::defaults());

        self::assertSame(
            ['…0002', '…0004', '…0003', '…0001'],
            array_map(static fn (RfqSummary $s): string => '…' . substr($s->record->publicId, -4), $page->items),
        );
    }

    public function test_query_parameters_are_strict(): void
    {
        $query = RfqQueueQuery::fromQueryParams(['status' => 'new,blocked', 'priority' => 'urgent', 'mine' => '1', 'page' => '2', 'pageSize' => '10', 'archived' => 'only']);

        self::assertCount(2, $query->statuses);
        self::assertTrue($query->mineOnly);
        self::assertSame(2, $query->page);
        self::assertSame(RfqQueueQuery::ARCHIVED_ONLY, $query->archived);

        foreach ([['status' => 'synced'], ['pageSize' => '500'], ['page' => '0'], ['page' => '-1'], ['archived' => 'maybe'], ['mine' => 'yes']] as $bad) {
            try {
                RfqQueueQuery::fromQueryParams($bad);
                self::fail('Accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
