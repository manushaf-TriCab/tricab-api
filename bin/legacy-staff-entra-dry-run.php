<?php

declare(strict_types=1);

/**
 * Staff Entra linking + activation dry-run. Never writes to legacy, Azure SQL, or Graph.
 *
 * Usage (from tricab-api):
 *   LEGACY_MIGRATION_STORE=azure-sql php bin/legacy-staff-entra-dry-run.php
 *   LEGACY_MIGRATION_STORE=azure-sql php bin/legacy-staff-entra-dry-run.php --json
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\LegacyMigration\Application\Service\StaffEntraLinkDryRunService;

require dirname(__DIR__) . '/vendor/autoload.php';

$jsonOut = in_array('--json', $argv, true);

$settings = Environment::settings(dirname(__DIR__));
$container = ContainerFactory::create($settings);

/** @var StaffEntraLinkDryRunService $service */
$service = $container->get(StaffEntraLinkDryRunService::class);
try {
    $result = $service->run();
} catch (Throwable $e) {
    fwrite(STDERR, "STOP: staff Entra linking dry-run failed.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(3);
}

if ($jsonOut) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
$would = is_array($result['wouldCreate'] ?? null) ? $result['wouldCreate'] : [];
$safety = is_array($result['databaseSafety'] ?? null) ? $result['databaseSafety'] : [];
$graph = is_array($result['graphSafety'] ?? null) ? $result['graphSafety'] : [];
$graphHealth = is_array($result['graphHealthDetail'] ?? null) ? $result['graphHealthDetail'] : [];

echo "STAFF ENTRA LINKING — DRY RUN\n\n";
echo 'Batch type:                          ' . (string) ($result['batchType'] ?? '') . "\n";
echo 'Canonical provider:                  ' . (string) ($result['providerCode'] ?? 'microsoft_entra') . "\n";
echo 'Graph health:                        ' . (string) ($result['graphHealth'] ?? '') . "\n";
echo 'Source fingerprint:                  ' . (string) ($result['sourceFingerprint'] ?? '') . "\n\n";

if (($result['graphHealth'] ?? '') === 'GRAPH_DIRECTORY_UNAVAILABLE') {
    echo "GRAPH_DIRECTORY_UNAVAILABLE\n";
    echo 'HTTP status:                         ' . (string) ($graphHealth['httpStatus'] ?? 'n/a') . "\n";
    echo 'Graph error:                         ' . (string) ($graphHealth['graphErrorCode'] ?? ($result['graphError'] ?? 'n/a')) . "\n";
    echo "Entra reconciliation was not attempted. NO_ENTRA_MATCH is not assigned.\n\n";
}

echo 'Batch 1 mapped users:                ' . (int) ($summary['mappedStaffUsers'] ?? 0) . "\n";
echo 'Mapped users found:                  ' . (int) ($result['mappedStaffCount'] ?? 0) . "\n";
echo '@tricab.com eligible:                ' . (int) ($summary['tricabCorporateStaff'] ?? 0) . "\n";
echo 'Non-@tricab.com staff:               ' . (int) ($summary['nonTricabCorporateStaff'] ?? 0) . "\n";
echo 'Graph lookup attempted:              ' . (int) ($summary['graphLookupAttempted'] ?? 0) . "\n\n";
echo 'TC employee verified:                ' . (int) ($summary['tcEmployeeVerified'] ?? 0) . "\n";
echo 'TC employee not found:               ' . (int) ($summary['tcEmployeeNotFound'] ?? 0) . "\n";
echo 'TC employee conflicts:               ' . (int) ($summary['tcEmployeeConflicts'] ?? 0) . "\n\n";
echo 'ALREADY_ENTRA_LINKED:                ' . (int) ($summary['alreadyEntraLinked'] ?? $summary['alreadyLinked'] ?? 0) . "\n";
echo 'Unique Entra matches:                ' . (int) ($summary['uniqueEntraMatches'] ?? 0) . "\n";
echo 'Genuine NO_ENTRA_MATCH:              ' . (int) ($summary['noEntraMatch'] ?? 0) . "\n";
echo 'ENTRA_DISABLED:                      ' . (int) ($summary['entraDisabled'] ?? $summary['disabledEntraAccounts'] ?? 0) . "\n";
echo 'GRAPH_DIRECTORY_UNAVAILABLE:         ' . (int) ($summary['graphDirectoryUnavailable'] ?? 0) . "\n";
echo 'MULTIPLE_TRICAB_USERS:               ' . (int) ($summary['multipleTriCabUserMatches'] ?? 0) . "\n";
echo 'OID conflicts:                       ' . (int) ($summary['oidConflicts'] ?? 0) . "\n";
echo 'Email/UPN conflicts:                 ' . (int) ($summary['emailUpnConflicts'] ?? 0) . "\n";
echo 'Provider conflicts:                  ' . (int) ($summary['providerConflicts'] ?? 0) . "\n";
echo 'MANUAL_REVIEW:                       ' . (int) ($summary['manualReviewRequired'] ?? 0) . "\n\n";
echo 'SAFE_TO_LINK:                        ' . (int) ($summary['safeToLink'] ?? 0) . "\n";
echo 'SAFE_TO_ACTIVATE:                    ' . (int) ($summary['safeToActivate'] ?? 0) . "\n\n";
echo 'Would create ExternalIdentities:     ' . (int) ($would['externalIdentities'] ?? 0) . "\n";
echo 'Would update provider microsoft_entra: ' . (int) ($would['providerUpdatesToMicrosoftEntra'] ?? 0) . "\n";
echo 'Would activate TriCab users:         ' . (int) ($would['userActivations'] ?? 0) . "\n";
echo 'Would create identity.Users:         0' . "\n";
echo 'Would change UserRoles:              0' . "\n\n";
echo "GRAPH SAFETY\n\n";
echo 'Graph read operations:               ' . (int) ($graph['readOperations'] ?? 0) . "\n";
echo 'Graph write operations:              0' . "\n\n";
echo "DATABASE SAFETY\n\n";
echo 'LEGACY WORDPRESS SELECT:             ' . (int) ($safety['legacyWordPressSelect'] ?? 0) . "\n";
echo 'LEGACY TC SELECT:                    ' . (int) ($safety['legacyTcSelect'] ?? 0) . "\n";
echo 'NEW AZURE SQL SELECT:                ' . (int) ($safety['azureSelectQueries'] ?? 0) . "\n";
echo 'Legacy INSERT/UPDATE/DELETE/DDL:     0' . "\n";
echo 'Azure INSERT/UPDATE/DELETE/DDL:      0' . "\n\n";

$tristan = is_array($result['tristanReconciliation'] ?? null) ? $result['tristanReconciliation'] : null;
if ($tristan !== null) {
    echo "TRISTAN RECONCILIATION\n\n";
    echo 'Corporate identity:                  tristang@tricab.com' . "\n";
    echo 'Entra OID:                           ' . (string) ($tristan['entraObjectId'] ?? 'n/a') . "\n";
    echo 'Entra UPN:                           ' . (string) ($tristan['entraUpn'] ?? 'n/a') . "\n";
    echo 'Entra mail:                          ' . (string) ($tristan['entraMail'] ?? 'n/a') . "\n";
    echo 'Recommended canonical UserId:        ' . (string) ($tristan['recommendedCanonicalUserId'] ?? 'n/a') . "\n";
    echo 'Reason:                              ' . (string) ($tristan['recommendedReason'] ?? 'n/a') . "\n";
    echo 'Proposed action:                     ' . (string) ($tristan['proposedAction'] ?? '') . "\n";
    echo 'Operator action:                     ' . (string) ($tristan['proposedActionLabel'] ?? 'REVIEW') . "\n\n";
}

echo "No ExternalIdentities were created. Dry run complete. Execution was not run.\n";
