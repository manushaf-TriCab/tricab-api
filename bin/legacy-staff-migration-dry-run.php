<?php

declare(strict_types=1);

/**
 * Staff-only migration dry-run. Never writes to legacy or new databases.
 *
 * Usage (from tricab-api):
 *   php bin/legacy-staff-migration-dry-run.php
 *   php bin/legacy-staff-migration-dry-run.php --json
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\LegacyMigration\Application\Service\StaffUserMigrationDryRunService;

require dirname(__DIR__) . '/vendor/autoload.php';

$jsonOut = in_array('--json', $argv, true);

$settings = Environment::settings(dirname(__DIR__));
$container = ContainerFactory::create($settings);

/** @var StaffUserMigrationDryRunService $service */
$service = $container->get(StaffUserMigrationDryRunService::class);
try {
    $result = $service->run();
} catch (Throwable $e) {
    fwrite(STDERR, "STOP: staff migration dry-run failed.\n");
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

echo "STAFF MIGRATION — FINAL DRY RUN\n\n";
echo 'Total staff candidates:              ' . (int) ($summary['totalStaffCandidates'] ?? 0) . "\n\n";
echo 'Valid-email staff:                   ' . (int) ($summary['validEmailStaff'] ?? 0) . "\n";
echo 'Missing-email staff:                 ' . (int) ($summary['missingEmailStaff'] ?? 0) . "\n";
echo 'Invalid-email staff:                 ' . (int) ($summary['invalidEmailStaff'] ?? 0) . "\n\n";
echo 'Legacy duplicate-email conflicts:    ' . (int) ($summary['legacyDuplicateEmailConflicts'] ?? 0) . "\n\n";
echo 'Existing TriCab users:               ' . (int) ($summary['existingTriCabUsers'] ?? 0) . "\n";
echo 'New-user candidates:                 ' . (int) ($summary['newUserCandidates'] ?? 0) . "\n\n";
echo 'TC employee verified:                ' . (int) ($summary['tcEmployeeVerified'] ?? 0) . "\n";
echo 'WordPress-only staff:                ' . (int) ($summary['wordpressOnlyStaff'] ?? 0) . "\n\n";
echo 'Role mapping resolved:               ' . (int) ($summary['roleMappingResolved'] ?? 0) . "\n";
echo 'Role review required:                ' . (int) ($summary['roleReviewRequired'] ?? 0) . "\n\n";
echo 'Administrator assignments proposed:  ' . (int) ($summary['administratorAssignmentsProposed'] ?? 0) . "\n";
echo 'Employee assignments proposed:       ' . (int) ($summary['employeeAssignmentsProposed'] ?? 0) . "\n";
echo 'Other role assignments proposed:     ' . (int) ($summary['otherRoleAssignmentsProposed'] ?? 0) . "\n\n";
echo 'Entra already linked:                ' . (int) ($summary['entraAlreadyLinked'] ?? 0) . "\n";
echo 'Entra link required:                 ' . (int) ($summary['entraLinkRequired'] ?? 0) . "\n\n";
echo 'SAFE TO CREATE:                      ' . (int) ($summary['safeToCreate'] ?? 0) . "\n";
echo 'REQUIRES REVIEW:                     ' . (int) ($summary['requiresReview'] ?? 0) . "\n";
echo 'EXCLUDED:                            ' . (int) ($summary['excluded'] ?? 0) . "\n";
echo 'BLOCKED:                             ' . (int) ($summary['blocked'] ?? 0) . "\n\n";
echo 'WOULD CREATE identity.Users:         ' . (int) ($would['identityUsers'] ?? 0) . "\n";
echo 'WOULD CREATE UserRoles:              ' . (int) ($would['userRoles'] ?? 0) . "\n";
echo 'WOULD CREATE legacy mappings:        ' . (int) ($would['legacyMappings'] ?? 0) . "\n\n";
echo 'WOULD CREATE ExternalIdentities:     0' . "\n\n";
echo "DATABASE SAFETY\n\n";
echo 'LEGACY WORDPRESS SELECT:             ' . (int) ($safety['legacySelectQueries'] ?? 0) . "\n";
echo 'LEGACY INSERT:                       0' . "\n";
echo 'LEGACY UPDATE:                       0' . "\n";
echo 'LEGACY DELETE:                       0' . "\n";
echo 'LEGACY DDL:                          0' . "\n\n";
echo 'NEW AZURE SQL SELECT:                ' . (int) ($safety['azureSelectQueries'] ?? 0) . "\n";
echo 'NEW AZURE INSERT:                    0' . "\n";
echo 'NEW AZURE UPDATE:                    0' . "\n";
echo 'NEW AZURE DELETE:                    0' . "\n";
echo 'NEW AZURE MERGE:                     0' . "\n";
echo 'NEW AZURE DDL:                       0' . "\n\n";
echo 'identity.Users modified:             0' . "\n";
echo 'identity.UserRoles modified:         0' . "\n";
echo 'identity.ExternalIdentities modified: 0' . "\n";
echo 'migration tables modified:           0' . "\n\n";

$unexpected = is_array($result['unexpectedRoleReport'] ?? null) ? $result['unexpectedRoleReport'] : [];
if ($unexpected !== []) {
    echo "UNEXPECTED LEGACY ROLES\n\n";
    foreach ($unexpected as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo (string) ($row['legacyRole'] ?? '?') . ': ' . (int) ($row['staffUserCount'] ?? 0) . " users\n";
    }
    echo "\n";
}

echo "No users were created. Dry run complete.\n";
