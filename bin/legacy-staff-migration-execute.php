<?php

declare(strict_types=1);

/**
 * Executes Staff Migration Batch 1 after fresh revalidation.
 *
 * Usage:
 *   php bin/legacy-staff-migration-execute.php --fingerprint=<sha256> --approved
 *   php bin/legacy-staff-migration-execute.php --fingerprint=<sha256> --approved --json
 */

require __DIR__ . '/../vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\LegacyMigration\Application\Service\StaffUserMigrationExecuteService;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationBatch1Policy;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationScope;

$json = in_array('--json', $argv, true);
$fingerprint = '';
$approved = in_array('--approved', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--fingerprint=')) {
        $fingerprint = substr($arg, strlen('--fingerprint='));
    }
}

if ($fingerprint === '' || !$approved) {
    fwrite(STDERR, "Usage: php bin/legacy-staff-migration-execute.php --fingerprint=<sha256> --approved [--json]\n");
    exit(1);
}

$container = ContainerFactory::create(Environment::settings(dirname(__DIR__)));
/** @var StaffUserMigrationExecuteService $service */
$service = $container->get(StaffUserMigrationExecuteService::class);

$actor = new ActorContext(
    userId: 1,
    publicId: '00000000-0000-4000-8000-000000000001',
    emailAddress: 'migration-cli@tricab.com',
);

try {
    $result = $service->execute($actor, [
        'scope' => StaffMigrationScope::STAFF_ONLY,
        'sourceFingerprint' => $fingerprint,
        'expectedCandidateCount' => StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
        'approved' => true,
    ]);
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo "STAFF MIGRATION BATCH 1\n";
        echo 'Batch ID: ' . ($result['batchId'] ?? '') . PHP_EOL;
        echo 'Source fingerprint: ' . ($result['sourceFingerprint'] ?? '') . PHP_EOL;
        echo 'Succeeded: ' . ($result['succeeded'] ?? 0) . PHP_EOL;
        echo 'Failed: ' . ($result['failed'] ?? 0) . PHP_EOL;
        echo 'Skipped: ' . ($result['skipped'] ?? 0) . PHP_EOL;
        echo 'Batch status: ' . ($result['batchStatus'] ?? '') . PHP_EOL;
    }
    exit(($result['batchStatus'] ?? '') === 'completed' ? 0 : 1);
} catch (Throwable $exception) {
    if ($json) {
        echo json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}
