<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

/**
 * Computes extractionAllowed / safetyLevel without altering grant detection.
 */
final class LegacyMigrationExtractionPolicy
{
    public const OVERRIDE_WARNING = 'WARNING: The configured legacy MySQL credential has write privileges. '
        . 'Extraction is being permitted only because the explicit development-only override is enabled. '
        . 'Application-level SQL remains read-only. Do not use this configuration for staging or production.';

    /**
     * @param array<string, mixed> $base Health report before extraction policy fields are applied.
     * @return array<string, mixed>
     */
    public function apply(
        array $base,
        bool $readOnlyConfigured,
        ?LegacyMigrationDevelopmentOverride $developmentOverride = null,
    ): array {
        $connected = !empty($base['connected']);
        $sslRequired = !empty($base['sslRequired']);
        $sslVerified = !empty($base['ssl']);
        $databaseMatches = isset($base['database'], $base['expectedDatabase'])
            && strcasecmp((string) $base['database'], (string) $base['expectedDatabase']) === 0;
        $selectOnlyGrantVerified = !empty($base['readOnlyVerified']);

        $developmentOverrideAllowed = $developmentOverride !== null
            && $developmentOverride->allowsWriteCapableReader($readOnlyConfigured)
            && $connected
            && $databaseMatches
            && (!$sslRequired || $sslVerified);
        $developmentOverrideEnabled = $developmentOverrideAllowed && !$selectOnlyGrantVerified;

        $safeForExtraction = !empty($base['safeForExtraction']);
        $extractionAllowed = $readOnlyConfigured
            && $connected
            && $databaseMatches
            && (!$sslRequired || $sslVerified)
            && ($selectOnlyGrantVerified || $developmentOverrideAllowed);

        $safetyLevel = LegacyMigrationDevelopmentOverride::SAFETY_BLOCKED;
        if ($safeForExtraction) {
            $safetyLevel = LegacyMigrationDevelopmentOverride::SAFETY_SELECT_ONLY;
        } elseif ($extractionAllowed && $developmentOverrideEnabled) {
            $safetyLevel = LegacyMigrationDevelopmentOverride::SAFETY_DEVELOPMENT_OVERRIDE;
        }

        $message = (string) ($base['message'] ?? '');
        if ($developmentOverrideEnabled) {
            $message = self::OVERRIDE_WARNING . ($message !== '' ? ' ' . $message : '');
        }

        $health = (string) ($base['health'] ?? 'blocked');
        if ($extractionAllowed && $developmentOverrideEnabled) {
            $health = 'development-override';
        } elseif ($safeForExtraction) {
            $health = 'healthy';
        } elseif (!$extractionAllowed) {
            $health = 'blocked';
        }

        return array_merge($base, [
            'selectOnlyGrantVerified' => $selectOnlyGrantVerified,
            'developmentOverrideEnabled' => $developmentOverrideEnabled,
            'extractionAllowed' => $extractionAllowed,
            'safeForExtraction' => $safeForExtraction,
            'safetyLevel' => $safetyLevel,
            'health' => $health,
            'message' => $message,
        ]);
    }
}
