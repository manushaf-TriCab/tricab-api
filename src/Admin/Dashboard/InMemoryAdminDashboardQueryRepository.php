<?php

declare(strict_types=1);

namespace TriCab\Api\Admin\Dashboard;

/**
 * Memory-mode stub: returns an empty but well-formed dashboard payload.
 * Production Azure SQL uses {@see AzureSqlAdminDashboardQueryRepository}.
 */
final class InMemoryAdminDashboardQueryRepository implements AdminDashboardQueryRepository
{
    public function fetchSnapshot(string $activityRange = '30d'): array
    {
        $range = $this->normalizeRange($activityRange);

        return [
            'summary' => [
                'totalProducts' => 0,
                'publishedProducts' => 0,
                'draftProducts' => 0,
                'users' => 0,
                'contacts' => 0,
                'contactsNew' => 0,
                'catalogueHealthPercent' => 0,
                'blockerCount' => 0,
            ],
            'productStatus' => [
                'total' => 0,
                'published' => 0,
                'draft' => 0,
                'archived' => 0,
                'inactive' => 0,
            ],
            'catalogueHealth' => [
                'classification' => 0,
                'markets' => 0,
                'media' => 0,
                'specifications' => 0,
                'publicationReady' => 0,
            ],
            'attention' => [],
            'recentProducts' => [],
            'recentActivity' => [],
            'recentUsers' => [],
            'recentContacts' => [],
            'productActivity' => $this->fetchProductActivity($range),
        ];
    }

    public function fetchProductActivity(string $range = '30d'): array
    {
        $normalized = $this->normalizeRange($range);
        $days = match ($normalized) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        $points = [];
        $cursor = new \DateTimeImmutable(sprintf('-%d days', $days - 1), new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        while ($cursor <= $end) {
            $points[] = [
                'date' => $cursor->format('Y-m-d'),
                'created' => 0,
                'updated' => 0,
                'published' => 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'range' => $normalized,
            'points' => $points,
        ];
    }

    private function normalizeRange(string $range): string
    {
        $normalized = strtolower(trim($range));

        return in_array($normalized, ['7d', '30d', '90d'], true) ? $normalized : '30d';
    }
}
