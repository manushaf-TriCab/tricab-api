<?php

declare(strict_types=1);

/**
 * Read-only Graph health probe. Never prints tokens or secrets.
 *
 * Usage: php bin/legacy-staff-entra-graph-health.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\LegacyMigration\Contract\EntraDirectoryReader;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Environment::settings(dirname(__DIR__));
$container = ContainerFactory::create($settings);

/** @var EntraDirectoryReader $reader */
$reader = $container->get(EntraDirectoryReader::class);
$tenantId = trim($settings->entraTenantId !== '' ? $settings->entraTenantId : $settings->graphTenantId);

$reader->clearTokenCache();
$health = $reader->verifyHealth($tenantId);
$roles = is_array($health['tokenRoles'] ?? null) ? $health['tokenRoles'] : [];
$roleList = [];
foreach ($roles as $role) {
    if (is_string($role) && $role !== '') {
        $roleList[] = $role;
    }
}

echo "GRAPH HEALTH\n\n";
echo 'App / client ID:                     ' . trim($settings->graphClientId) . "\n";
echo 'Tenant ID:                           ' . $tenantId . "\n";
echo 'Secret configured:                   ' . (trim($settings->graphClientSecret) !== '' ? 'true' : 'false') . "\n";
echo 'Graph configured:                    ' . ($reader->isConfigured() ? 'true' : 'false') . "\n";
echo 'Graph authentication:                ' . (($health['authenticated'] ?? false) ? 'true' : 'false') . "\n";
echo 'Directory read:                      ' . (($health['directoryRead'] ?? false) ? 'true' : 'false') . "\n";
echo 'HTTP status:                         ' . (string) ($health['httpStatus'] ?? 'n/a') . "\n";
echo 'Graph error:                         ' . (string) ($health['graphErrorCode'] ?? 'none') . "\n";
echo 'Graph User.Read.All:                 ' . (($health['hasUserReadAll'] ?? false) ? 'true' : 'false') . "\n";
echo 'Token application roles:             ' . ($roleList === [] ? '(none visible)' : implode(', ', $roleList)) . "\n";
echo 'Graph writes:                        0' . "\n";

if (($health['directoryRead'] ?? false) !== true) {
    echo "\nGRAPH_DIRECTORY_UNAVAILABLE\n";
    exit(2);
}

echo "\nGraph directory access is healthy.\n";
exit(0);
