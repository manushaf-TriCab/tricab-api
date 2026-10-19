<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Npr\Contract\ManagerRouteRepository;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\ManagerRoute;
use TriCab\Core\Npr\Domain\NewManagerRoute;
use TriCab\Core\Npr\Domain\NprPerson;

/**
 * `[npr].[ManagerRoutes]` on Azure SQL. Overlap is application code, not a
 * constraint (see the schema): a write reads the branch's routes under a range
 * lock inside its transaction, so two concurrent writes for one branch queue
 * and the second sees the first.
 */
final class AzureSqlManagerRouteRepository implements ManagerRouteRepository
{
    use AzureSqlTransaction;

    private const SELECT = '
        SELECT [ManagerRouteId], [Branch], [ManagerUserId], [ManagerEmail], [ManagerName], [IsDelegate],
               CONVERT(VARCHAR(10), [ValidFrom], 23) AS [ValidFrom], CONVERT(VARCHAR(10), [ValidTo], 23) AS [ValidTo],
               [ApprovedByUserId]
        FROM [npr].[ManagerRoutes]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function all(): array
    {
        return array_map(self::route(...), $this->connection->fetchAll(self::SELECT . ' ORDER BY [Branch], [ValidFrom], [ManagerRouteId]'));
    }

    public function find(int $id): ?ManagerRoute
    {
        $row = $this->connection->fetchAll(self::SELECT . ' WHERE [ManagerRouteId] = :id', ['id' => $id])[0] ?? null;

        return $row === null ? null : self::route($row);
    }

    public function activeFor(string $branch, DateTimeImmutable $day): array
    {
        return array_map(self::route(...), $this->connection->fetchAll(
            self::SELECT . ' WHERE [Branch] = :branch AND [ValidFrom] <= :day AND ([ValidTo] IS NULL OR [ValidTo] >= :day2)
             ORDER BY [IsDelegate] DESC, [ManagerRouteId]',
            ['branch' => trim($branch), 'day' => $day->format('Y-m-d'), 'day2' => $day->format('Y-m-d')],
        ));
    }

    public function create(NewManagerRoute $route): ManagerRoute
    {
        $id = $this->runInTransaction($this->connection, function () use ($route): int {
            $this->assertNoOverlap($route->branch, $route->isDelegate, $route->validFrom, $route->validTo, null);
            $inserted = $this->connection->fetchAll(
                'INSERT INTO [npr].[ManagerRoutes]
                    ([Branch], [ManagerUserId], [ManagerEmail], [ManagerName], [IsDelegate], [ValidFrom], [ValidTo], [ApprovedByUserId])
                 OUTPUT INSERTED.[ManagerRouteId]
                 VALUES (:branch, :userId, :email, :name, :delegate, :validFrom, :validTo, :approvedBy)',
                [
                    'branch' => $route->branch,
                    'userId' => $route->manager->userId,
                    'email' => $route->manager->email,
                    'name' => $route->manager->name,
                    'delegate' => $route->isDelegate ? 1 : 0,
                    'validFrom' => $route->validFrom->format('Y-m-d'),
                    'validTo' => $route->validTo?->format('Y-m-d'),
                    'approvedBy' => $route->approvedByUserId,
                ],
            )[0] ?? throw new RuntimeException('Manager route insert returned no row.');

            return AzureSqlValueMapper::intValue($inserted['ManagerRouteId'] ?? null);
        });

        return $this->find($id) ?? throw new RuntimeException('Manager route vanished after insert.');
    }

    public function setValidTo(int $id, ?DateTimeImmutable $validTo): ?ManagerRoute
    {
        $found = $this->runInTransaction($this->connection, function () use ($id, $validTo): bool {
            $current = $this->connection->fetchAll(
                self::SELECT . ' WITH (UPDLOCK, HOLDLOCK) WHERE [ManagerRouteId] = :id',
                ['id' => $id],
            )[0] ?? null;
            if ($current === null) {
                return false;
            }
            $route = self::route($current);
            $this->assertNoOverlap($route->branch, $route->isDelegate, $route->validFrom, $validTo, $id);
            $this->connection->execute(
                'UPDATE [npr].[ManagerRoutes] SET [ValidTo] = :validTo WHERE [ManagerRouteId] = :id',
                ['validTo' => $validTo?->format('Y-m-d'), 'id' => $id],
            );

            return true;
        });

        return $found ? $this->find($id) : null;
    }

    private function assertNoOverlap(string $branch, bool $isDelegate, DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $exceptId): void
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . ' WITH (UPDLOCK, HOLDLOCK) WHERE [Branch] = :branch AND [IsDelegate] = :delegate',
            ['branch' => trim($branch), 'delegate' => $isDelegate ? 1 : 0],
        );
        foreach ($rows as $row) {
            $route = self::route($row);
            if ($route->id !== $exceptId && $route->overlaps($branch, $isDelegate, $from, $to)) {
                throw new NprWorkflowRefused(NprWorkflowRefused::ROUTE_OVERLAP, sprintf("An active %s route for '%s' already covers part of that period.", $isDelegate ? 'delegate' : 'branch', $branch));
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function route(array $row): ManagerRoute
    {
        $validTo = AzureSqlValueMapper::nullableString($row['ValidTo'] ?? null);

        return new ManagerRoute(
            AzureSqlValueMapper::intValue($row['ManagerRouteId'] ?? null),
            AzureSqlValueMapper::stringValue($row['Branch'] ?? null),
            new NprPerson(
                AzureSqlValueMapper::intValue($row['ManagerUserId'] ?? null),
                AzureSqlValueMapper::stringValue($row['ManagerEmail'] ?? null),
                AzureSqlValueMapper::stringValue($row['ManagerName'] ?? null),
            ),
            AzureSqlValueMapper::boolValue($row['IsDelegate'] ?? null),
            new DateTimeImmutable(AzureSqlValueMapper::stringValue($row['ValidFrom'] ?? null), new DateTimeZone('UTC')),
            $validTo === null ? null : new DateTimeImmutable($validTo, new DateTimeZone('UTC')),
            AzureSqlValueMapper::nullableInt($row['ApprovedByUserId'] ?? null),
        );
    }
}
