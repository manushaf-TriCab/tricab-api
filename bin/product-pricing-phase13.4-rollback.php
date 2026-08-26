<?php

declare(strict_types=1);

/**
 * PHASE 13.4 — Rollback TCA pilot batch (soft-delete migration-owned rows only).
 *
 *   php bin/product-pricing-phase13.4-rollback.php \
 *     --batch=pricing-p13.4-tca-BVA-... \
 *     --confirm-non-production \
 *     --confirm-rollback
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$coreSrc = dirname(__DIR__) . '/core/src';
spl_autoload_register(static function (string $class) use ($coreSrc): void {
    if (!str_starts_with($class, 'TriCab\\Core\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('TriCab\\Core\\'))) . '.php';
    $path = $coreSrc . '/' . $relative;
    if (is_file($path)) {
        require $path;
    }
}, true, true);

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Pricing\Phase134MigrationOwnership;
use TriCab\Core\Product\Migration\Pricing\Phase134PilotCliGuard;

$argvList = $argv ?? [];
if (!Phase134PilotCliGuard::rollbackConfirmationsOk($argvList)) {
    fwrite(STDERR, Phase134PilotCliGuard::ROLLBACK_CONFIRMATIONS_REQUIRED . PHP_EOL);
    exit(3);
}
$batchId = Phase134PilotCliGuard::parseBatchId($argvList);
$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$appEnv = (string) ($env['APP_ENV'] ?? '');
$dbHost = (string) ($env['DB_HOST'] ?? '');
$dbDatabase = (string) ($env['DB_DATABASE'] ?? '');
if (in_array(strtolower($appEnv), ['production', 'prod'], true)
    || !str_contains(strtolower($dbHost), 'tricab-mel')
    || strtolower($dbDatabase) !== 'tc'
) {
    fwrite(STDERR, "STOP: environment not approved\n");
    exit(2);
}

$c = ContainerFactory::create(Environment::settings($root));
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);
$session = $db->fetchAll('SELECT @@SERVERNAME AS s, DB_NAME() AS d');
if (strtolower((string) ($session[0]['s'] ?? '')) !== 'tricab-mel') {
    fwrite(STDERR, "STOP: unexpected server\n");
    exit(2);
}

$prefix = Phase134MigrationOwnership::SOURCE_PREFIX . str_replace("'", "''", $batchId) . '|';
$rows = $db->fetchAll(
    "SELECT ProductVariantPriceId, CONVERT(NVARCHAR(36), PublicId) AS PublicId, ErpSourceReference, UpdatedAtUtc, CreatedAtUtc
     FROM [product].[ProductVariantPrices]
     WHERE DeletedAtUtc IS NULL
       AND ErpSourceReference LIKE :pfx",
    [':pfx' => $prefix . '%'],
);

$pdo = $c->get(PDO::class);
$deleted = 0;
$protected = 0;
try {
    $pdo->beginTransaction();
    foreach ($rows as $row) {
        $ref = (string) ($row['ErpSourceReference'] ?? '');
        if (!Phase134MigrationOwnership::isBatchOwned($ref, (string) $batchId)) {
            ++$protected;
            continue;
        }
        // Protect if source was changed to manual
        if (!str_starts_with($ref, Phase134MigrationOwnership::SOURCE_PREFIX)) {
            ++$protected;
            continue;
        }
        $stmt = $pdo->prepare("UPDATE [product].[ProductVariantPrices]
            SET IsActive=0, DeletedAtUtc=SYSUTCDATETIME(), UpdatedAtUtc=SYSUTCDATETIME()
            WHERE ProductVariantPriceId=:id AND DeletedAtUtc IS NULL
              AND ErpSourceReference LIKE :pfx");
        $stmt->execute([
            ':id' => (int) $row['ProductVariantPriceId'],
            ':pfx' => $prefix . '%',
        ]);
        $deleted += $stmt->rowCount();
    }
    $pdo->commit();
    $out = [
        'decision' => 'PILOT_ROLLBACK_SUCCESS',
        'batchId' => $batchId,
        'deleted' => $deleted,
        'protected' => $protected,
        'note' => 'Reference data (legacy-tc / TCA-BASE / AUD) was NOT removed.',
    ];
    file_put_contents($root . '/tmp/phase13.4-tca-pilot-rollback.json', json_encode($out, JSON_PRETTY_PRINT) . "\n");
    echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, json_encode(['decision' => 'PILOT_ROLLBACK_FAILED', 'error' => $e->getMessage()], JSON_PRETTY_PRINT) . "\n");
    exit(4);
}
