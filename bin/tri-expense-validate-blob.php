<?php
declare(strict_types=1);
/**
 * Prove the TriExpense blob container is reachable and writable.
 *
 * Touches only a throwaway object under phase5-validation/, never a real
 * receipt, and deletes it again. Sequence: PUT → HEAD → read → DELETE → HEAD
 * (expected miss). SAS URLs and account keys are never printed.
 *
 * Usage: php bin/tri-expense-validate-blob.php [--keep]
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Product\Contract\ProductBlobStore;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$argvList = $argv ?? [];
$keep = in_array('--keep', $argvList, true);

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);

$containerName = trim($settings->blobContainerTriexpense) !== ''
    ? trim($settings->blobContainerTriexpense)
    : \TriCab\Core\Expense\Module::BLOB_CONTAINER;

echo "APP_ENV={$settings->appEnv}\n";
echo "BLOB_AUTH_MODE={$settings->blobAuthMode}\n";
echo 'BLOB_ACCOUNT_CONFIGURED=' . (trim($settings->blobAccountName) !== '' ? 'YES' : 'NO') . "\n";
echo "CONTAINER={$containerName}\n";

if (!$settings->isProductBlobEnabled()) {
    fwrite(STDERR, "BLOB_DISABLED: set BLOB_AUTH_MODE=connection-string (or memory locally)\n");
    exit(4);
}

/** @var ProductBlobStore $blobs */
$blobs = $container->get(ProductBlobStore::class);

// Non-production only: ensure the dedicated container exists before the round-trip.
if (!$settings->isProduction() && strtolower($settings->blobAuthMode) === 'connection-string') {
    try {
        $rest = new \TriCab\Api\Product\Storage\AzureBlobRestClient(
            $settings->blobAccountName,
            $settings->blobAccountKey,
            $settings->blobEndpoint,
        );
        $rest->ensureContainer($containerName);
        echo "CONTAINER_ENSURE=ok\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'CONTAINER_ENSURE_FAILED: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$path = 'phase5-validation/' . bin2hex(random_bytes(16)) . '.txt';
$payload = "tri-expense phase5 blob validation " . gmdate('c') . "\n";
$results = [];
$exitCode = 0;

try {
    $put = $blobs->putObject($containerName, $path, 'text/plain', $payload);
    $results['PUT'] = 'ok bytes=' . strlen($payload) . ' storedLength=' . ($put->contentLength ?? strlen($payload));
} catch (Throwable $e) {
    $results['PUT'] = 'FAILED ' . $e->getMessage();
    foreach ($results as $step => $outcome) {
        echo "{$step}: {$outcome}\n";
    }
    fwrite(STDERR, "BLOB_VALIDATION_FAILED at PUT\n");
    exit(1);
}

try {
    $head = $blobs->headObject($containerName, $path);
    $results['HEAD'] = $head !== null
        ? 'ok contentLength=' . $head->contentLength
        : 'FAILED object not found after PUT';
    $exitCode = $head !== null ? $exitCode : 1;
} catch (Throwable $e) {
    $results['HEAD'] = 'FAILED ' . $e->getMessage();
    $exitCode = 1;
}

try {
    $read = $blobs->getObjectBinary($containerName, $path);
    $results['READ'] = $read === $payload
        ? 'ok round-trip matched'
        : 'FAILED round-trip mismatch';
    $exitCode = $read === $payload ? $exitCode : 1;
} catch (Throwable $e) {
    $results['READ'] = 'FAILED ' . $e->getMessage();
    $exitCode = 1;
}

if ($keep) {
    $results['DELETE'] = 'skipped (--keep)';
} else {
    try {
        $blobs->deleteObject($containerName, $path);
        $results['DELETE'] = 'ok';
    } catch (Throwable $e) {
        $results['DELETE'] = 'FAILED ' . $e->getMessage();
        $exitCode = 1;
    }

    try {
        $results['HEAD_AFTER_DELETE'] = $blobs->headObject($containerName, $path) === null
            ? 'ok object gone'
            : 'FAILED object still present';
    } catch (Throwable $e) {
        $results['HEAD_AFTER_DELETE'] = 'FAILED ' . $e->getMessage();
        $exitCode = 1;
    }
}

foreach ($results as $step => $outcome) {
    echo "{$step}: {$outcome}\n";
}

echo "REAL_RECEIPTS_TOUCHED=0\n";
echo $exitCode === 0 ? "BLOB_VALIDATION_OK\n" : "BLOB_VALIDATION_FAILED\n";
exit($exitCode);
