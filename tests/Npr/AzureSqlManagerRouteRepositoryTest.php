<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Npr\Persistence\AzureSqlManagerRouteRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\NewManagerRoute;
use TriCab\Core\Npr\Domain\NprPerson;

/** The SQL the route repository sends, against the fake connection. */
final class AzureSqlManagerRouteRepositoryTest extends TestCase
{
    private FakeSqlConnection $connection;
    private AzureSqlManagerRouteRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new FakeSqlConnection();
        $this->repository = new AzureSqlManagerRouteRepository($this->connection);
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $from, ?string $to, int $delegate = 0): array
    {
        return [
            'ManagerRouteId' => $id,
            'Branch' => 'Brisbane',
            'ManagerUserId' => 30,
            'ManagerEmail' => 'shanep@tricab.com',
            'ManagerName' => 'Shane Plumridge',
            'IsDelegate' => $delegate,
            'ValidFrom' => $from,
            'ValidTo' => $to,
            'ApprovedByUserId' => null,
        ];
    }

    private static function new(string $from, ?string $to, bool $delegate = false): NewManagerRoute
    {
        $utc = new DateTimeZone('UTC');

        return new NewManagerRoute(
            'Brisbane',
            new NprPerson(30, 'shanep@tricab.com', 'Shane Plumridge'),
            $delegate,
            new DateTimeImmutable($from, $utc),
            $to === null ? null : new DateTimeImmutable($to, $utc),
            1,
        );
    }

    public function test_active_routes_are_read_for_the_branch_and_day_delegates_first(): void
    {
        $this->connection->queueFetch([self::row(2, '2026-10-01', '2026-10-31', 1), self::row(1, '2026-01-01', null)]);

        $routes = $this->repository->activeFor(' Brisbane ', new DateTimeImmutable('2026-10-19', new DateTimeZone('UTC')));

        $call = $this->connection->calls[0];
        self::assertStringContainsString('[ValidFrom] <= :day AND ([ValidTo] IS NULL OR [ValidTo] >= :day2)', $call['sql']);
        self::assertStringContainsString('ORDER BY [IsDelegate] DESC', $call['sql']);
        self::assertSame(['branch' => 'Brisbane', 'day' => '2026-10-19', 'day2' => '2026-10-19'], $call['params']);
        self::assertTrue($routes[0]->isDelegate);
        self::assertSame('2026-10-31', $routes[0]->validTo?->format('Y-m-d'));
        self::assertNull($routes[1]->validTo);
        self::assertSame(30, $routes[1]->manager->userId);
    }

    public function test_a_create_checks_overlap_under_a_lock_then_inserts(): void
    {
        $this->connection->queueFetch([self::row(1, '2026-01-01', '2026-06-30')]);
        $this->connection->queueFetch([['ManagerRouteId' => 9]]);
        $this->connection->queueFetch([self::row(9, '2026-07-01', null)]);

        $created = $this->repository->create(self::new('2026-07-01', null));

        self::assertSame(9, $created->id);
        self::assertTrue($this->connection->committed);
        self::assertStringContainsString('WITH (UPDLOCK, HOLDLOCK)', $this->connection->calls[0]['sql']);
        self::assertSame(['branch' => 'Brisbane', 'delegate' => 0], $this->connection->calls[0]['params']);
        self::assertStringContainsString('INSERT INTO [npr].[ManagerRoutes]', $this->connection->calls[1]['sql']);
        self::assertSame('2026-07-01', $this->connection->calls[1]['params']['validFrom']);
        self::assertNull($this->connection->calls[1]['params']['validTo']);
        self::assertSame(1, $this->connection->calls[1]['params']['approvedBy']);
    }

    public function test_an_overlapping_create_is_refused_and_rolled_back(): void
    {
        $this->connection->queueFetch([self::row(1, '2026-01-01', null)]);

        try {
            $this->repository->create(self::new('2026-07-01', null));
            self::fail('expected an overlap');
        } catch (NprWorkflowRefused $refused) {
            self::assertSame('NPR_ROUTE_OVERLAP', $refused->reason);
        }
        self::assertTrue($this->connection->rolledBack);
        self::assertCount(1, $this->connection->calls, 'nothing inserted');
    }

    public function test_ending_a_route_ignores_itself_when_checking_overlap(): void
    {
        $this->connection->queueFetch([self::row(1, '2026-01-01', null)]);
        $this->connection->queueFetch([self::row(1, '2026-01-01', null)]);
        $this->connection->queueFetch([self::row(1, '2026-01-01', '2026-12-31')]);

        $route = $this->repository->setValidTo(1, new DateTimeImmutable('2026-12-31', new DateTimeZone('UTC')));

        self::assertSame('2026-12-31', $route?->validTo?->format('Y-m-d'));
        self::assertStringContainsString('UPDATE [npr].[ManagerRoutes] SET [ValidTo] = :validTo', $this->connection->calls[2]['sql']);
        self::assertSame('2026-12-31', $this->connection->calls[2]['params']['validTo']);
    }

    public function test_ending_a_missing_route_is_null(): void
    {
        $this->connection->queueFetch([]);

        self::assertNull($this->repository->setValidTo(5, null));
        self::assertCount(1, $this->connection->calls);
    }
}
