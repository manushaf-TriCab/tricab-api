<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

/**
 * Computes a fingerprint over staff analysis source data for stale-data protection.
 */
final class StaffMigrationSnapshotFingerprint
{
    /**
     * @param array<string, mixed> $analysisResult
     */
    public function fromAnalysis(array $analysisResult): string
    {
        $summary = is_array($analysisResult['summary'] ?? null) ? $analysisResult['summary'] : [];
        $items = is_array($analysisResult['items'] ?? null) ? $analysisResult['items'] : [];
        $payload = [
            'totalStaffCandidates' => $summary['totalStaffCandidates'] ?? 0,
            'staffWithValidEmail' => $summary['staffWithValidEmail'] ?? 0,
            'duplicateEmailStaff' => $summary['duplicateEmailStaff'] ?? 0,
            'existingTriCabUserMatches' => $summary['existingTriCabUserMatches'] ?? 0,
            'itemIds' => array_map(
                static fn (array $item): string => (string) ($item['legacyWordPressUserId'] ?? '') . ':' . strtolower((string) ($item['normalizedEmail'] ?? '')),
                array_values(array_filter($items, 'is_array')),
            ),
        ];
        sort($payload['itemIds']);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
