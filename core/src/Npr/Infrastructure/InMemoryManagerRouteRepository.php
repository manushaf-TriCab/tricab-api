<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Npr\Contract\ManagerRouteRepository;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\ManagerRoute;
use TriCab\Core\Npr\Domain\NewManagerRoute;

final class InMemoryManagerRouteRepository implements ManagerRouteRepository
{
    /** @var array<int, ManagerRoute> */
    private array $routes = [];

    private int $sequence = 0;

    public function all(): array
    {
        $routes = array_values($this->routes);
        usort($routes, static fn (ManagerRoute $a, ManagerRoute $b): int => strcasecmp($a->branch, $b->branch) ?: $a->validFrom <=> $b->validFrom ?: $a->id <=> $b->id);

        return $routes;
    }

    public function find(int $id): ?ManagerRoute
    {
        return $this->routes[$id] ?? null;
    }

    public function activeFor(string $branch, DateTimeImmutable $day): array
    {
        $active = array_values(array_filter(
            $this->routes,
            static fn (ManagerRoute $route): bool => strcasecmp($route->branch, trim($branch)) === 0 && $route->isActiveOn($day),
        ));
        usort($active, static fn (ManagerRoute $a, ManagerRoute $b): int => (int) $b->isDelegate <=> (int) $a->isDelegate ?: $a->id <=> $b->id);

        return $active;
    }

    public function create(NewManagerRoute $route): ManagerRoute
    {
        $this->assertNoOverlap($route->branch, $route->isDelegate, $route->validFrom, $route->validTo, null);
        $this->sequence++;

        return $this->routes[$this->sequence] = new ManagerRoute(
            $this->sequence,
            $route->branch,
            $route->manager,
            $route->isDelegate,
            $route->validFrom,
            $route->validTo,
            $route->approvedByUserId,
        );
    }

    public function setValidTo(int $id, ?DateTimeImmutable $validTo): ?ManagerRoute
    {
        $current = $this->routes[$id] ?? null;
        if ($current === null) {
            return null;
        }
        $this->assertNoOverlap($current->branch, $current->isDelegate, $current->validFrom, $validTo, $id);

        return $this->routes[$id] = new ManagerRoute(
            $current->id,
            $current->branch,
            $current->manager,
            $current->isDelegate,
            $current->validFrom,
            $validTo,
            $current->approvedByUserId,
        );
    }

    private function assertNoOverlap(string $branch, bool $isDelegate, DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $exceptId): void
    {
        foreach ($this->routes as $route) {
            if ($route->id !== $exceptId && $route->overlaps($branch, $isDelegate, $from, $to)) {
                throw new NprWorkflowRefused(NprWorkflowRefused::ROUTE_OVERLAP, sprintf("An active %s route for '%s' already covers part of that period.", $isDelegate ? 'delegate' : 'branch', $branch));
            }
        }
    }
}
