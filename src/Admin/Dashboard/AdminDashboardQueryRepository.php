<?php

declare(strict_types=1);

namespace TriCab\Api\Admin\Dashboard;

/**
 * Aggregate read model for the admin home dashboard.
 * Implementations must use COUNT / GROUP BY queries — never load full product/user lists.
 */
interface AdminDashboardQueryRepository
{
    /**
     * @return array{
     *   summary: array<string, int|float|null>,
     *   productStatus: array<string, int>,
     *   catalogueHealth: array<string, int>,
     *   attention: list<array{code: string, label: string, count: int, severity: string, href: string}>,
     *   recentProducts: list<array<string, mixed>>,
     *   recentActivity: list<array<string, mixed>>,
     *   recentUsers: list<array<string, mixed>>,
     *   recentContacts: list<array<string, mixed>>,
     *   productActivity: array{range: string, points: list<array<string, mixed>>}
     * }
     */
    public function fetchSnapshot(string $activityRange = '30d'): array;

    /**
     * @return array{range: string, points: list<array<string, mixed>>}
     */
    public function fetchProductActivity(string $range = '30d'): array;
}
