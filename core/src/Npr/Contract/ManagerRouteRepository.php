<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

use DateTimeImmutable;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\ManagerRoute;
use TriCab\Core\Npr\Domain\NewManagerRoute;

/** `[npr].[ManagerRoutes]`: branch routes and dated delegate overrides. */
interface ManagerRouteRepository
{
    /** @return list<ManagerRoute> by branch, then start date */
    public function all(): array;

    public function find(int $id): ?ManagerRoute;

    /**
     * Routes for the branch (any case) that are active on that day, delegates first.
     *
     * @return list<ManagerRoute>
     */
    public function activeFor(string $branch, DateTimeImmutable $day): array;

    /** @throws NprWorkflowRefused ROUTE_OVERLAP when a route of the same kind shares a day */
    public function create(NewManagerRoute $route): ManagerRoute;

    /**
     * Changes the end date of a route (null re-opens it).
     *
     * @throws NprWorkflowRefused ROUTE_OVERLAP
     */
    public function setValidTo(int $id, ?DateTimeImmutable $validTo): ?ManagerRoute;
}
