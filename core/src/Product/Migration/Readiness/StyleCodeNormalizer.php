<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Readiness;

/**
 * Safe style_code normalization for review. Never silently splits comma values.
 */
final class StyleCodeNormalizer
{
    /**
     * @return array{
     *   raw: string,
     *   normalized: ?string,
     *   status: string,
     *   issues: list<string>,
     *   isCommaSeparated: bool,
     *   isNumeric: bool,
     *   isBlank: bool
     * }
     */
    public function normalize(string $raw): array
    {
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $trimmed = trim($decoded);
        $issues = [];

        if ($trimmed === '') {
            return [
                'raw' => $raw,
                'normalized' => null,
                'status' => MigrationReadinessStatus::INVALID,
                'issues' => ['blank_style_code'],
                'isCommaSeparated' => false,
                'isNumeric' => false,
                'isBlank' => true,
            ];
        }

        if (preg_match('/[,;|\/]/', $trimmed) === 1) {
            return [
                'raw' => $raw,
                'normalized' => null,
                'status' => MigrationReadinessStatus::REVIEW,
                'issues' => ['comma_or_multi_style_code'],
                'isCommaSeparated' => true,
                'isNumeric' => false,
                'isBlank' => false,
            ];
        }

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            return [
                'raw' => $raw,
                'normalized' => $trimmed,
                'status' => MigrationReadinessStatus::REVIEW,
                'issues' => ['numeric_legacy_style'],
                'isCommaSeparated' => false,
                'isNumeric' => true,
                'isBlank' => false,
            ];
        }

        // Case-insensitive match key only; raw preserved separately.
        $normalized = strtoupper($trimmed);
        if ($normalized !== $trimmed) {
            $issues[] = 'case_normalized_for_match_only';
        }
        if ($trimmed !== $raw && trim($raw) !== $trimmed) {
            $issues[] = 'whitespace_or_entity_trimmed';
        }

        return [
            'raw' => $raw,
            'normalized' => $normalized,
            'status' => MigrationReadinessStatus::READY,
            'issues' => $issues,
            'isCommaSeparated' => false,
            'isNumeric' => false,
            'isBlank' => false,
        ];
    }
}
