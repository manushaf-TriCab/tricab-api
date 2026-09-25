<?php

declare(strict_types=1);

/**
 * Onboarding migration from tricab-training-vercel (Phase 7): extract → preview
 * → approve → execute → verify → rollback.
 *
 * Production execution is DISABLED (OnboardingMigrationPolicy): execute and
 * rollback accept only a synthetic snapshot whose every address is at
 * example.test / example.invalid. Extraction and preview are read-only.
 *
 * Batch files — the snapshot and the approved plan — are written under
 * var/onboarding-migration/<batch>/ (git-ignored, mode 0600). A real snapshot
 * holds personal and payroll data: keep it on the operator's machine only.
 *
 * Usage:
 *   php bin/onboarding-migration.php synthetic --out=FILE [--load-postgres=postgres://…@127.0.0.1…]
 *   php bin/onboarding-migration.php extract --out=FILE [--admin-emails=a@x,b@x]
 *        (reads ONBOARDING_MIGRATION_SOURCE_DATABASE_URL; read-only transaction)
 *   php bin/onboarding-migration.php preview --snapshot=FILE [--actor=you@tricab.com] [--json]
 *        (without --actor: prints the plan only; with it: records a batch to approve)
 *   php bin/onboarding-migration.php execute --batch=ID --fingerprint=SHA256 --approved --actor=you@tricab.com
 *   php bin/onboarding-migration.php verify --batch=ID
 *   php bin/onboarding-migration.php rollback --batch=ID --actor=you@tricab.com --i-understand-this-removes-migrated-records
 *   php bin/onboarding-migration.php status --batch=ID
 *
 * Exit codes: 0 ok, 2 usage, 3 refused, 4 verification found problems, 5 failed.
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Onboarding\Migration\AzureSqlOnboardingMigrationExecutor;
use TriCab\Api\Onboarding\Migration\AzureSqlOnboardingMigrationLedger;
use TriCab\Api\Onboarding\Migration\AzureSqlOnboardingMigrationRollback;
use TriCab\Api\Onboarding\Migration\AzureSqlOnboardingMigrationVerifier;
use TriCab\Api\Onboarding\Migration\AzureSqlTargetCatalogueReader;
use TriCab\Api\Onboarding\Migration\AzureSqlTargetStateReader;
use TriCab\Api\Onboarding\Migration\LocalPostgresSyntheticLoader;
use TriCab\Api\Onboarding\Migration\PostgresSourceSnapshotExtractor;
use TriCab\Api\Onboarding\Migration\SyntheticSourceBuilder;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\Service\CreateOnboardingPersonService;
use TriCab\Core\Onboarding\Application\Service\DeleteOnboardingPersonService;
use TriCab\Core\Onboarding\Application\Service\GeneratePolicyDocumentService;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Migration\OnboardingMigrationPolicy;
use TriCab\Core\Onboarding\Migration\OnboardingMigrationRefused;
use TriCab\Core\Onboarding\Migration\Plan\MigrationPlan;
use TriCab\Core\Onboarding\Migration\Plan\MigrationPlanner;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

const BATCH_DIR = __DIR__ . '/../var/onboarding-migration';

// getopt() stops at the first non-option, which here is the command itself.
$command = $argv[1] ?? '';
$known = ['out', 'load-postgres', 'admin-emails', 'snapshot', 'actor', 'json', 'batch', 'fingerprint', 'approved', 'i-understand-this-removes-migrated-records'];
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m) !== 1 || !in_array($m[1], $known, true)) {
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(2);
    }
    $options[$m[1]] = $m[2] ?? false;
}

/** @return never */
function fail(int $code, string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function option(array $options, string $name): ?string
{
    return is_string($options[$name] ?? null) && trim($options[$name]) !== '' ? trim($options[$name]) : null;
}

function writePrivate(string $path, string $contents): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        fail(5, 'Cannot create ' . dirname($path));
    }
    file_put_contents($path, $contents);
    chmod($path, 0600);
}

function loadSnapshot(string $path): SourceSnapshot
{
    $json = @file_get_contents($path);
    if (!is_string($json)) {
        fail(2, 'Cannot read ' . $path);
    }

    return SourceSnapshot::fromJson($json);
}

function batchDir(string $batch): string
{
    if (preg_match('/^[0-9a-f-]{36}$/', $batch) !== 1) {
        fail(2, '--batch must be a batch id.');
    }

    return BATCH_DIR . '/' . $batch;
}

/** The operator: an active user who may manage onboarding people, as the imports resolve theirs. */
function actor(SqlConnection $db, ?string $email): ActorContext
{
    if ($email === null) {
        fail(2, 'ACTOR_REQUIRED: this step is done in somebody\'s name: --actor=you@tricab.com');
    }
    $rows = $db->fetchAll(
        "SELECT TOP 1 u.UserId, LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS PublicId, u.EmailAddress
         FROM [identity].[Users] u
         INNER JOIN [identity].[UserStatuses] s ON s.UserStatusId = u.UserStatusId AND s.StatusCode = N'active'
         INNER JOIN [identity].[UserRoles] ur ON ur.UserId = u.UserId AND ur.IsActive = 1
         INNER JOIN [identity].[RolePermissions] rp ON rp.RoleId = ur.RoleId AND rp.IsActive = 1
         INNER JOIN [identity].[Permissions] p ON p.PermissionId = rp.PermissionId AND UPPER(p.PermissionCode) = :permission
         WHERE LOWER(u.NormalizedEmailAddress) = :email",
        [':email' => strtolower($email), ':permission' => CreateOnboardingPersonService::PERMISSION],
    );
    if ($rows === []) {
        fail(3, sprintf('ACTOR_NOT_PERMITTED: %s is not an active user holding %s.', $email, CreateOnboardingPersonService::PERMISSION));
    }

    return new ActorContext((int) $rows[0]['UserId'], (string) $rows[0]['PublicId'], (string) $rows[0]['EmailAddress'], 'onboarding-migration-cli');
}

function printPlan(MigrationPlan $plan): void
{
    $counts = $plan->counts();
    echo sprintf("Snapshot %s%s\n", substr($plan->snapshotHash, 0, 16), $plan->synthetic ? ' (synthetic)' : '');
    echo 'People:  ' . json_encode($counts['people']) . "\n";
    echo 'Create:  ' . json_encode(array_filter($counts['create'])) . "\n";
    echo 'Skip:    ' . json_encode($counts['skip']) . "\n";
    echo "\nFindings:\n";
    foreach ($plan->findings as $f) {
        echo sprintf("  [%s] %s\n", $f['severity'], $f['message']);
    }
    echo sprintf("\nApprovable: %s\nFingerprint: %s\n", $plan->isApprovable() ? 'yes' : 'NO (blocking findings)', $plan->fingerprint());
}

try {
    if ($command === 'synthetic') {
        $out = option($options, 'out') ?? fail(2, '--out=FILE is required.');
        $container = ContainerFactory::create(Environment::settings(dirname(__DIR__)));
        $catalogue = (new AzureSqlTargetCatalogueReader($container->get(SqlConnection::class)))->read();
        $document = (new SyntheticSourceBuilder($catalogue))->build(new DateTimeImmutable('now'));
        $snapshot = SourceSnapshot::fromArray($document);
        writePrivate($out, json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        echo sprintf("Synthetic snapshot written: %s (hash %s)\n", $out, substr($snapshot->hash(), 0, 16));
        if (($url = option($options, 'load-postgres')) !== null) {
            LocalPostgresSyntheticLoader::connect($url)->load($snapshot);
            echo "Loaded into the local Postgres. Extract it with ONBOARDING_MIGRATION_SOURCE_DATABASE_URL set to the same URL.\n";
        }
        exit(0);
    }

    if ($command === 'extract') {
        $out = option($options, 'out') ?? fail(2, '--out=FILE is required.');
        $url = getenv('ONBOARDING_MIGRATION_SOURCE_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            fail(2, 'Set ONBOARDING_MIGRATION_SOURCE_DATABASE_URL (postgres://…) for this run.');
        }
        $admins = array_values(array_filter(array_map('trim', explode(',', option($options, 'admin-emails') ?? ''))));
        // Synthetic only when it comes from this machine; a remote source is real.
        $synthetic = in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true);
        $document = PostgresSourceSnapshotExtractor::connect($url)->extract($synthetic, $admins, new DateTimeImmutable('now'));
        $snapshot = SourceSnapshot::fromArray($document);
        writePrivate($out, json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        echo sprintf("Extracted %d trainees into %s (hash %s, %s).\n", $snapshot->count('trainees'), $out, substr($snapshot->hash(), 0, 16), $synthetic ? 'synthetic, local source' : 'REAL source — contains personal data');
        exit(0);
    }

    $settings = Environment::settings(dirname(__DIR__));
    if (!$settings->usesAzureSqlUserRepository()) {
        fail(2, 'USER_REPOSITORY is not azure-sql; there is no platform database to migrate into.');
    }
    $container = ContainerFactory::create($settings);
    /** @var SqlConnection $db */
    $db = $container->get(SqlConnection::class);
    $ledger = new AzureSqlOnboardingMigrationLedger($db);
    $preview = static function (SourceSnapshot $snapshot) use ($db, $container): array {
        $catalogue = (new AzureSqlTargetCatalogueReader($db))->read();
        $state = (new AzureSqlTargetStateReader($db, $container->get(OnboardingAccountDirectory::class)))->read($snapshot, $catalogue);

        return [(new MigrationPlanner())->plan($snapshot, $catalogue, $state), $catalogue];
    };

    if ($command === 'preview') {
        $snapshot = loadSnapshot(option($options, 'snapshot') ?? fail(2, '--snapshot=FILE is required.'));
        [$plan] = $preview($snapshot);
        if (array_key_exists('json', $options)) {
            echo json_encode(['counts' => $plan->counts(), 'findings' => $plan->findings, 'fingerprint' => $plan->fingerprint(), 'approvable' => $plan->isApprovable()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            printPlan($plan);
        }
        if (($email = option($options, 'actor')) === null) {
            echo "\nRead-only preview. Re-run with --actor=you@tricab.com to record a batch that can be approved.\n";
            exit(0);
        }
        if (!$plan->isApprovable()) {
            fail(3, 'NOT_APPROVABLE: fix the blocking findings in the source and extract again.');
        }
        $ledger->assertInstalled();
        $actor = actor($db, $email);
        $counts = $plan->counts();
        $batch = $ledger->createBatch($actor, $plan->fingerprint(), $plan->snapshotHash, $plan->synthetic, [
            'sourceRecords' => array_sum($plan->sourceCounts),
            'people' => $counts['people'],
            'create' => $counts['create'],
            'skip' => $counts['skip'],
        ]);
        writePrivate(batchDir($batch) . '/snapshot.json', json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        writePrivate(batchDir($batch) . '/plan.json', json_encode($plan->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        echo sprintf("\nBatch %s recorded for approval.\nTo run it:\n  php bin/onboarding-migration.php execute --batch=%s --fingerprint=%s --approved --actor=%s\n", $batch, $batch, $plan->fingerprint(), $actor->emailAddress);
        exit(0);
    }

    $batch = option($options, 'batch') ?? fail(2, '--batch=ID is required.');
    $dir = batchDir($batch);

    if ($command === 'status') {
        echo json_encode($ledger->batch($batch), JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }

    $snapshot = loadSnapshot($dir . '/snapshot.json');
    $record = $ledger->batch($batch);
    if ($record['snapshotHash'] !== $snapshot->hash()) {
        fail(3, 'SNAPSHOT_CHANGED: the batch\'s snapshot file is not the one that was previewed.');
    }
    $blobs = $container->get(ProductBlobStore::class);
    $verifier = new AzureSqlOnboardingMigrationVerifier($db, $ledger, $blobs);

    if ($command === 'execute') {
        $fingerprint = option($options, 'fingerprint') ?? fail(2, '--fingerprint=SHA256 is required (from the preview).');
        if (!array_key_exists('approved', $options)) {
            fail(2, 'APPROVAL_REQUIRED: add --approved to confirm you have reviewed the preview for this fingerprint.');
        }
        OnboardingMigrationPolicy::assertExecutable($snapshot);
        $actor = actor($db, option($options, 'actor'));
        if (!hash_equals($record['fingerprint'], $fingerprint)) {
            fail(3, 'FINGERPRINT_MISMATCH: that is not the fingerprint this batch was previewed with.');
        }
        [$plan, $catalogue] = $preview($snapshot);
        if (!hash_equals($fingerprint, $plan->fingerprint())) {
            fail(3, 'STALE_PREVIEW: the platform or the source changed since the preview. Preview again and approve the new plan.');
        }

        $ledger->acquireLock($actor);
        try {
            $ledger->transition($batch, $actor, [AzureSqlOnboardingMigrationLedger::STATUS_PREVIEW], AzureSqlOnboardingMigrationLedger::STATUS_EXECUTING, 'execute', ['fingerprint' => $fingerprint]);
            $executor = new AzureSqlOnboardingMigrationExecutor(
                $db,
                $container->get(ApplicationTransaction::class),
                $ledger,
                $container->get(CreateOnboardingPersonService::class),
                $container->get(OnboardingAccountDirectory::class),
                $blobs,
                $settings->blobContainerOnboarding,
                $container->get(GeneratePolicyDocumentService::class),
            );
            $result = $executor->execute($plan, $snapshot, $catalogue, $batch, $actor);
            $counts = $plan->counts();
            $ledger->recordCounts($batch, array_sum($counts['create']), array_sum($counts['skip']), count(array_filter($plan->findings, static fn (array $f): bool => $f['severity'] === 'warning')));
            $failed = $result['failed'] !== [];
            $ledger->transition($batch, $actor, [AzureSqlOnboardingMigrationLedger::STATUS_EXECUTING], $failed ? AzureSqlOnboardingMigrationLedger::STATUS_FAILED : AzureSqlOnboardingMigrationLedger::STATUS_COMPLETED, 'execute.finish', ['migrated' => count($result['migrated']), 'failed' => count($result['failed'])]);
        } finally {
            $ledger->releaseLock();
        }
        writePrivate($dir . '/execute.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo sprintf("Migrated %d people; %d failed; %d policy PDFs generated.\n", count($result['migrated']), count($result['failed']), $result['policyDocuments']);
        foreach ($result['failed'] as $email => $error) {
            echo sprintf("  FAILED %s: %s\n", $email, $error);
        }
        $report = $verifier->verify($batch, $plan, $snapshot, $catalogue);
        writePrivate($dir . '/verify.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "\nVerification: " . ($report['ok'] ? 'OK' : 'PROBLEMS') . "\n";
        foreach ($report['counts'] as $kind => $c) {
            echo sprintf("  %-17s plan %4d  ledger %4d%s\n", $kind, $c['expected'], $c['actual'], $c['expected'] === $c['actual'] ? '' : '  <-- differs');
        }
        foreach ($report['problems'] as $problem) {
            echo '  ! ' . $problem . "\n";
        }
        exit($failed ? 5 : ($report['ok'] ? 0 : 4));
    }

    if ($command === 'verify') {
        if ($record['status'] === AzureSqlOnboardingMigrationLedger::STATUS_ROLLED_BACK) {
            $report = $verifier->verifyRolledBack($batch);
            echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
            exit($report['ok'] ? 0 : 4);
        }
        $planDocument = json_decode((string) file_get_contents($dir . '/plan.json'), true);
        [, $catalogue] = $preview($snapshot);
        // The approved plan is what is reconciled against, not a fresh one — a
        // fresh preview would now find everything already migrated.
        $approved = new MigrationPlan(
            $snapshot->hash(),
            $snapshot->isSynthetic(),
            is_array($planDocument['people'] ?? null) ? $planDocument['people'] : [],
            is_array($planDocument['findings'] ?? null) ? $planDocument['findings'] : [],
            is_array($planDocument['sourceCounts'] ?? null) ? $planDocument['sourceCounts'] : [],
            '',
            '',
        );
        $report = $verifier->verify($batch, $approved, $snapshot, $catalogue);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit($report['ok'] ? 0 : 4);
    }

    if ($command === 'rollback') {
        if (!array_key_exists('i-understand-this-removes-migrated-records', $options)) {
            fail(2, 'Add --i-understand-this-removes-migrated-records: rollback removes what the batch created (audit rows stay).');
        }
        OnboardingMigrationPolicy::assertExecutable($snapshot);
        $actor = actor($db, option($options, 'actor'));
        if (!in_array($record['status'], [AzureSqlOnboardingMigrationLedger::STATUS_COMPLETED, AzureSqlOnboardingMigrationLedger::STATUS_FAILED], true)) {
            fail(3, 'BATCH_STATE: only a completed or failed batch can be rolled back; this one is ' . $record['status'] . '.');
        }
        $ledger->acquireLock($actor);
        try {
            $rollback = new AzureSqlOnboardingMigrationRollback(
                $db,
                $container->get(ApplicationTransaction::class),
                $ledger,
                $container->get(DeleteOnboardingPersonService::class),
                $blobs,
            );
            $result = $rollback->rollback($batch, $actor);
            if ($result['refused'] === []) {
                $ledger->transition($batch, $actor, [AzureSqlOnboardingMigrationLedger::STATUS_COMPLETED, AzureSqlOnboardingMigrationLedger::STATUS_FAILED], AzureSqlOnboardingMigrationLedger::STATUS_ROLLED_BACK, 'rollback', ['people' => count($result['rolledBack'])]);
            }
        } finally {
            $ledger->releaseLock();
        }
        writePrivate($dir . '/rollback.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo sprintf("Rolled back %d people; refused %d; %d blobs deleted.\n", count($result['rolledBack']), count($result['refused']), $result['blobsDeleted']);
        foreach ($result['refused'] as $userId => $reasons) {
            echo sprintf("  REFUSED user %d: %s\n", $userId, implode('; ', $reasons));
        }
        foreach ($result['blobFailures'] as $failure) {
            echo '  blob not deleted: ' . $failure . "\n";
        }
        $report = $verifier->verifyRolledBack($batch);
        echo "\nAfter rollback: " . json_encode($report) . "\n";
        exit($result['refused'] !== [] ? 3 : ($report['ok'] ? 0 : 4));
    }

    fail(2, 'Unknown command. See the usage at the top of bin/onboarding-migration.php.');
} catch (OnboardingMigrationRefused $refused) {
    fail(3, $refused->getMessage());
}
