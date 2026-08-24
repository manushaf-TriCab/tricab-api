<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Support;

/**
 * Mapping artifact sampleValues are evidence only.
 * They must never become migrated specification payload values.
 */
final class MappingSampleValueInvariant
{
    public const RULE = 'MAPPING_ARTIFACT_SAMPLE_VALUES_ARE_EVIDENCE_ONLY';

    /**
     * @param list<string> $sampleValues
     */
    public static function isSampleEvidence(string $candidate, array $sampleValues): bool
    {
        $want = self::normalize($candidate);
        if ($want === '') {
            return false;
        }
        foreach ($sampleValues as $sample) {
            if (self::normalize($sample) === $want) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $projection
     */
    public static function hasRowProvenance(array $projection): bool
    {
        $table = MigrationPayload::string($projection['legacyTable'] ?? '');
        $rowId = MigrationPayload::string($projection['legacyRowId'] ?? $projection['legacyProductCode'] ?? '');
        $field = MigrationPayload::string($projection['legacyField'] ?? '');
        $raw = MigrationPayload::string($projection['rawValue'] ?? '');

        return $table !== '' && $rowId !== '' && $field !== '' && $raw !== '';
    }

    /**
     * @param array<string, mixed> $projection
     * @param list<string> $sampleValues
     */
    public static function payloadViolatesInvariant(array $projection, array $sampleValues): bool
    {
        if (!self::hasRowProvenance($projection)) {
            return true;
        }
        $normalized = MigrationPayload::string($projection['normalizedValue'] ?? '');
        $raw = MigrationPayload::string($projection['rawValue'] ?? '');
        if ($normalized === '' && $raw === '') {
            return true;
        }
        // A value that equals a sample is allowed only when it still traces to a live row.
        // Violation: payload taken from samples with no live row/column provenance.
        $sourceKind = MigrationPayload::string($projection['valueSource'] ?? '');
        if (in_array($sourceKind, ['sampleValues', 'mappingArtifactSample', 'fixtureSample'], true)) {
            return true;
        }

        return false;
    }

    public static function normalize(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && is_numeric($trimmed)) {
            return number_format((float) $trimmed, 2, '.', '');
        }

        return strtolower($trimmed);
    }
}
