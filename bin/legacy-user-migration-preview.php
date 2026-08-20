<?php

declare(strict_types=1);

/**
 * SELECT-only WordPress+TC user dry-run into migration staging.
 * Never executes user migration. Stops if extraction is not allowed.
 *
 * Usage (from tricab-api):
 *   php bin/legacy-user-migration-preview.php --health-only
 *   php bin/legacy-user-migration-preview.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\LegacyMigration\Application\Service\LegacyMigrationEngine;
use TriCab\Core\LegacyMigration\Contract\LegacySourceHealthProbe;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$healthOnly = in_array('--health-only', $argv, true);

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
    $envValue = static function (string $key): string {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }

        return (string) $value;
    };

    echo "LEGACY MYSQL CONNECTION HEALTH\n\n";
    echo "WordPress\n";
    echo "--------------------------------\n";
    echo 'Configured:                 ' . ($bool($wp['configured'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Connected:                  ' . ($bool($wp['connected'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Database:                   ' . (string) ($wp['database'] ?? 'null') . "\n";
    echo 'SSL verified:               ' . ($bool($wp['ssl'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Read-only configured:       ' . ($bool($wp['readOnlyConfigured'] ?? false) ? 'true' : 'false') . "\n";
    echo 'SELECT-only grant verified: ' . ($wpSelect ? 'true' : 'false') . "\n";
    echo 'Safe for extraction:        ' . ($bool($wp['safeForExtraction'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Development override:       ' . ($wpDevOverride ? 'true' : 'false') . "\n";
    echo 'Extraction allowed:         ' . ($wpExtract ? 'true' : 'false') . "\n";
    if (!empty($wp['message'])) {
        echo 'Message:                    ' . (string) $wp['message'] . "\n";
    }
    echo "\nTC\n";
    echo "--------------------------------\n";
    echo 'Configured:                 ' . ($bool($tc['configured'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Connected:                  ' . ($bool($tc['connected'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Database:                   ' . (string) ($tc['database'] ?? 'null') . "\n";
    echo 'SSL verified:               ' . ($bool($tc['ssl'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Read-only configured:       ' . ($bool($tc['readOnlyConfigured'] ?? false) ? 'true' : 'false') . "\n";
    echo 'SELECT-only grant verified: ' . ($tcSelect ? 'true' : 'false') . "\n";
    echo 'Safe for extraction:        ' . ($bool($tc['safeForExtraction'] ?? false) ? 'true' : 'false') . "\n";
    echo 'Development override:       ' . ($tcDevOverride ? 'true' : 'false') . "\n";
    echo 'Extraction allowed:         ' . ($tcExtract ? 'true' : 'false') . "\n";
    if (!empty($tc['message'])) {
        echo 'Message:                    ' . (string) $tc['message'] . "\n";
    }
    echo "\nSAFETY LEVEL:\n{$safetyLevel}\n";
    if ($wpDevOverride || $tcDevOverride) {
        echo "\nWARNING\n\nThe configured legacy MySQL credential has write privileges.\n";
        echo "Extraction is being permitted only because the explicit development-only override is enabled.\n";
        echo "Application-level SQL remains read-only.\n";
        echo "Do not use this configuration for staging or production.\n";
    }
    echo "\nRESULT:\n";
    if ($wpExtract && $tcExtract) {
        echo "READY FOR READ-ONLY USER EXTRACTION\n";
        exit(0);
    }
    echo "BLOCKED\n\nReason:\n" . trim((string) (($wp['message'] ?? '') . ' ' . ($tc['message'] ?? ''))) . "\n";
    exit(2);
}

if (!$wpExtract || !$tcExtract) {
    fwrite(STDERR, "STOP: legacy sources are not allowed for extraction. No extraction was performed.\n");
    exit(2);
}

/** @var LegacyMigrationEngine $engine */
$engine = $container->get(LegacyMigrationEngine::class);
$actor = new ActorContext(1, '00000000-0000-4000-8000-000000000001', 'legacy-migration-preview');
try {
    $batch = $engine->createBatch($actor, 'Live user dry-run ' . gmdate('Y-m-d H:i:s'), 'users', 'SELECT-only staging preview');
} catch (Throwable $e) {
    fwrite(STDERR, "STOP: migration batch could not be created. Extraction was not performed.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(3);
}
$id = (string) $batch['publicId'];
$engine->extract($actor, $id, false);
$engine->normalize($actor, $id);
$engine->validate($actor, $id);
$preview = $engine->preview($actor, $id);
$workspace = $engine->userWorkspace($id);
$stats = is_array($workspace['stats'] ?? null) ? $workspace['stats'] : [];
$framework = is_array($workspace['framework'] ?? null) ? $workspace['framework'] : [];
$persistence = (string) ($framework['persistenceMode'] ?? 'MEMORY');

echo "Migration batch ID: {$id}\n";
echo "LEGACY USER INVENTORY\n";
foreach ([
    'Total WordPress users' => $stats['totalExtracted'] ?? 'NOT MEASURED',
    'Staff-like users' => $stats['staff'] ?? 'NOT MEASURED',
    'Staff with employee match' => $stats['staffWithEmployeeMatch'] ?? 'NOT MEASURED',
    'Customer-like users' => $stats['customers'] ?? 'NOT MEASURED',
    'Customer-contact linked' => $stats['customerContacts'] ?? 'NOT MEASURED',
    'ALI-FLEX users' => $stats['aliFlexUsers'] ?? 'NOT MEASURED',
    'Missing email' => $stats['missingEmails'] ?? 'NOT MEASURED',
    'Invalid email' => $stats['invalidEmails'] ?? 'NOT MEASURED',
    'Duplicate normalized email groups' => $stats['duplicateNormalizedEmailGroups'] ?? 'NOT MEASURED',
    'Users affected by duplicate emails' => $stats['usersAffectedByDuplicateEmails'] ?? 'NOT MEASURED',
    'Unique contact matches' => $stats['uniqueContactMatches'] ?? 'NOT MEASURED',
    'Multiple contact matches' => $stats['multipleContactMatches'] ?? 'NOT MEASURED',
    'No contact match' => $stats['noContactMatch'] ?? 'NOT MEASURED',
    'Users with unknown roles' => $stats['unknownRoles'] ?? 'NOT MEASURED',
    'Staff requiring Entra linkage' => $stats['entraLinkRequired'] ?? 'NOT MEASURED',
    'Ready' => $stats['ready'] ?? 'NOT MEASURED',
    'Warnings' => $stats['warnings'] ?? 'NOT MEASURED',
    'Requires manual review' => $stats['requiresReview'] ?? 'NOT MEASURED',
    'Blocked' => $stats['blocked'] ?? 'NOT MEASURED',
] as $label => $value) {
    echo $label . ': ' . (is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value) . PHP_EOL;
}
echo "Roles discovered: " . json_encode($stats['rolesDiscovered'] ?? new stdClass(), JSON_THROW_ON_ERROR) . PHP_EOL;
echo "\nDEVELOPMENT EXTRACTION SAFETY\n";
echo 'APP_ENV: ' . (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production')) . PHP_EOL;
echo 'Development override enabled: ' . (($wpDevOverride || $tcDevOverride) ? 'true' : 'false') . PHP_EOL;
echo 'WP connected: ' . ($bool($wp['connected'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo 'TC connected: ' . ($bool($tc['connected'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo 'WP SSL verified: ' . ($bool($wp['ssl'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo 'TC SSL verified: ' . ($bool($tc['ssl'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo 'WP read-only configured: ' . ($bool($wp['readOnlyConfigured'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo 'TC read-only configured: ' . ($bool($tc['readOnlyConfigured'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo 'WP SELECT-only grant verified: ' . ($wpSelect ? 'true' : 'false') . PHP_EOL;
echo 'TC SELECT-only grant verified: ' . ($tcSelect ? 'true' : 'false') . PHP_EOL;
echo 'WP extraction allowed: ' . ($wpExtract ? 'true' : 'false') . PHP_EOL;
echo 'TC extraction allowed: ' . ($tcExtract ? 'true' : 'false') . PHP_EOL;
echo "Legacy write statements executed: 0\n";
echo "Production identity writes executed: 0\n";
echo "Password hashes selected: 0\n";
echo "Activation keys selected: 0\n";
echo "Session tokens staged: 0\n";
echo 'Migration persistence: ' . $persistence . PHP_EOL;
echo 'Migration batch ID: ' . $id . PHP_EOL;
echo "Preview blocking: " . json_encode($preview['blocking'] ?? null) . PHP_EOL;
echo "Would write production tables: " . json_encode($preview['wouldWriteProductionTables'] ?? null) . PHP_EOL;
