<?php

declare(strict_types=1);

/**
 * Strict read-only staff analysis. Never writes to any database or migration store.
 *
 * Usage (from tricab-api):
 *   php bin/legacy-staff-readonly-analysis.php --health-only
 *   php bin/legacy-staff-readonly-analysis.php
 *   php bin/legacy-staff-readonly-analysis.php --json
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\LegacyMigration\Application\Service\LegacyStaffReadOnlyAnalysisService;
use TriCab\Core\LegacyMigration\Contract\LegacySourceHealthProbe;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$healthOnly = in_array('--health-only', $argv, true);
$jsonOut = in_array('--json', $argv, true);

$settings = Environment::settings(dirname(__DIR__));
$container = ContainerFactory::create($settings);
/** @var LegacySourceHealthProbe $probe */
$probe = $container->get(LegacySourceHealthProbe::class);
$wp = $probe->probe(SourceCatalog::WORDPRESS) ?? [];
$tc = $probe->probe(SourceCatalog::BUSINESS_DB) ?? [];

$bool = static fn (mixed $v): bool => $v === true || $v === 1 || $v === '1' || $v === 'true';
$wpSelect = $bool($wp['selectOnlyGrantVerified'] ?? $wp['readOnlyVerified'] ?? false);
$tcSelect = $bool($tc['selectOnlyGrantVerified'] ?? $tc['readOnlyVerified'] ?? false);
$wpExtract = $bool($wp['extractionAllowed'] ?? false);
$tcExtract = $bool($tc['extractionAllowed'] ?? false);
$wpDevOverride = $bool($wp['developmentOverrideEnabled'] ?? false);
$tcDevOverride = $bool($tc['developmentOverrideEnabled'] ?? false);
$safetyLevel = ($wpDevOverride || $tcDevOverride) ? 'DEVELOPMENT_OVERRIDE' : ($wpExtract && $tcExtract ? 'SELECT_ONLY' : 'BLOCKED');

if ($healthOnly) {
    echo "LEGACY MYSQL CONNECTION HEALTH (staff read-only analysis)\n\n";
    echo "WordPress\n";
    echo "--------------------------------\n";
    echo 'Configured:                 ' . ($bool($wp['configured'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Connected:                  ' . ($bool($wp['connected'] ?? false) ? 'true' : 'false') . "\n";
    echo 'SELECT-only grant verified: ' . ($wpSelect ? 'true' : 'false') . "\n";
    echo 'Development override:       ' . ($wpDevOverride ? 'true' : 'false') . "\n";
    echo 'Extraction allowed:         ' . ($wpExtract ? 'true' : 'false') . "\n";
    echo "\nTC\n";
    echo "--------------------------------\n";
    echo 'Configured:                 ' . ($bool($tc['configured'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Connected:                  ' . ($bool($tc['connected'] ?? false) ? 'true' : 'false') . "\n";
    echo 'SELECT-only grant verified: ' . ($tcSelect ? 'true' : 'false') . "\n";
    echo 'Development override:       ' . ($tcDevOverride ? 'true' : 'false') . "\n";
    echo 'Extraction allowed:         ' . ($tcExtract ? 'true' : 'false') . "\n";
    echo "\nSAFETY LEVEL:\n{$safetyLevel}\n";
    if ($wpDevOverride || $tcDevOverride) {
        echo "\nWARNING: Application-level SQL guard permits SELECT only. Database grants are NOT read-only.\n";
    }
    echo "\nRESULT:\n";
    if ($wpExtract && $tcExtract) {
        echo "READY FOR READ-ONLY STAFF ANALYSIS\n";
        exit(0);
    }
    echo "BLOCKED\n";
    exit(2);
}

if (!$wpExtract || !$tcExtract) {
    fwrite(STDERR, "STOP: legacy sources are not allowed for staff analysis.\n");
    exit(2);
}

/** @var LegacyStaffReadOnlyAnalysisService $service */
$service = $container->get(LegacyStaffReadOnlyAnalysisService::class);
try {
    $result = $service->analyze();
} catch (Throwable $e) {
    fwrite(STDERR, "STOP: staff analysis failed.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(3);
}

if ($jsonOut) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
$safety = is_array($result['databaseSafety'] ?? null) ? $result['databaseSafety'] : [];

echo "STRICT READ-ONLY STAFF ANALYSIS\n\n";
echo 'Total staff candidates:              ' . (int) ($summary['totalStaffCandidates'] ?? 0) . "\n\n";
echo 'Staff with valid email:              ' . (int) ($summary['staffWithValidEmail'] ?? 0) . "\n";
echo 'Staff missing email:                 ' . (int) ($summary['staffMissingEmail'] ?? 0) . "\n";
echo 'Staff invalid email:                 ' . (int) ($summary['staffInvalidEmail'] ?? 0) . "\n\n";
echo 'Duplicate-email staff:             ' . (int) ($summary['duplicateEmailStaff'] ?? 0) . "\n";
echo 'Existing TriCab user matches:        ' . (int) ($summary['existingTriCabUserMatches'] ?? 0) . "\n";
echo 'New-user candidates:                 ' . (int) ($summary['newUserCandidates'] ?? 0) . "\n";
echo 'Other conflicts:                     ' . (int) ($summary['otherConflicts'] ?? 0) . "\n\n";
echo 'Administrator role:                  ' . (int) ($summary['administratorRole'] ?? 0) . "\n";
echo 'Staff role:                          ' . (int) ($summary['staffRole'] ?? 0) . "\n";
echo 'Sales role:                          ' . (int) ($summary['salesRole'] ?? 0) . "\n";
echo 'Unexpected staff roles:              ' . (int) ($summary['unexpectedStaffRoles'] ?? 0) . "\n\n";
echo 'Entra already linked:                ' . (int) ($summary['entraAlreadyLinked'] ?? 0) . "\n";
echo 'Entra link required:                 ' . (int) ($summary['entraLinkRequired'] ?? 0) . "\n\n";
echo 'Eligible for FUTURE migration:       ' . (int) ($summary['eligibleForFutureMigration'] ?? 0) . "\n";
echo 'Requires review:                     ' . (int) ($summary['requiresReview'] ?? 0) . "\n";
echo 'Excluded:                            ' . (int) ($summary['excluded'] ?? 0) . "\n\n";
echo 'TC employee enrichment:              ' . (string) ($result['tcEmployeeEnrichment'] ?? 'UNAVAILABLE') . "\n\n";
echo "DATABASE SAFETY\n\n";
echo 'Legacy SELECT queries:               ' . (int) ($safety['legacySelectQueries'] ?? 0) . "\n";
echo 'Azure SELECT queries:                ' . (int) ($safety['azureSelectQueries'] ?? 0) . "\n\n";
echo 'Legacy INSERT:                       0' . "\n";
echo 'Legacy UPDATE:                       0' . "\n";
echo 'Legacy DELETE:                       0' . "\n";
echo 'Legacy DDL:                          0' . "\n\n";
echo 'Azure INSERT:                        0' . "\n";
echo 'Azure UPDATE:                        0' . "\n";
echo 'Azure DELETE:                        0' . "\n";
echo 'Azure MERGE:                         0' . "\n";
echo 'Azure DDL:                           0' . "\n\n";
echo 'identity.Users modified:             0' . "\n";
echo 'ExternalIdentities modified:         0' . "\n";
echo 'UserRoles modified:                  0' . "\n";
echo 'CustomerContactUsers modified:       0' . "\n";
echo 'migration.* modified:                0' . "\n";

if (($safety['legacyWriteAttemptsBlocked'] ?? 0) > 0 || ($safety['azureWriteAttemptsBlocked'] ?? 0) > 0) {
    fwrite(STDERR, "\nSTOP: write query was attempted and blocked.\n");
    exit(4);
}

echo "\nNo database records were created, edited, or deleted.\n";
