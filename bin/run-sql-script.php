<?php

declare(strict_types=1);

/**
 * Runs a .sql script against the configured database, splitting on GO batch
 * separators (which PDO cannot execute in a single statement).
 *
 * Intended for the idempotent development scripts under database/scripts.
 * Each batch runs on its own; scripts are responsible for their own
 * transactions and re-runnability.
 *
 * Usage (from tricab-api):
 *   php bin/run-sql-script.php database/scripts/development/alter-product-regions.sql
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$relativePath = $argv[1] ?? null;

if ($relativePath === null) {
    fwrite(STDERR, "Usage: php bin/run-sql-script.php <path-to-sql-file>\n");
    exit(1);
}

$path = str_starts_with($relativePath, '/') ? $relativePath : $basePath . '/' . $relativePath;
if (!is_file($path)) {
    fwrite(STDERR, sprintf("SQL script not found: %s\n", $path));
    exit(1);
}

$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, sprintf("Unable to read SQL script: %s\n", $path));
    exit(1);
}

/** @var list<string> $batches */
$batches = [];
$current = [];

foreach (preg_split('/\R/', $sql) ?: [] as $line) {
    if (preg_match('/^\s*GO\s*(?:--.*)?$/i', $line) === 1) {
        $batches[] = implode("\n", $current);
        $current = [];
        continue;
    }
    $current[] = $line;
}
$batches[] = implode("\n", $current);

$batches = array_values(array_filter(
    $batches,
    static fn (string $batch): bool => trim($batch) !== '',
));

$settings = Environment::settings($basePath);
if ($settings->appEnv === 'production') {
    fwrite(STDERR, "STOP: APP_ENV=production — development SQL scripts cannot run here.\n");
    exit(1);
}
$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

printf("Running %s (%d batch%s)...\n", basename($path), count($batches), count($batches) === 1 ? '' : 'es');

foreach ($batches as $index => $batch) {
    $label = sprintf('batch %d/%d', $index + 1, count($batches));

    try {
        $db->execute($batch);
        echo "  ok {$label}\n";
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fwrite(STDERR, sprintf("  failed %s: %s\n", $label, $exception->getMessage()));
        exit(1);
    }
}

echo "Script complete.\n";
