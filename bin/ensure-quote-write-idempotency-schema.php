<?php

declare(strict_types=1);

/**
 * NON-PRODUCTION: ensure quote.QuoteWriteIdempotency on tricab-mel / tc.
 *
 * Usage:
 *   php bin/ensure-quote-write-idempotency-schema.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$env = $db->fetchAll('SELECT @@SERVERNAME AS [serverName], DB_NAME() AS [dbName], SUSER_SNAME() AS [userName]');
$server = (string) ($env[0]['serverName'] ?? '');
$database = (string) ($env[0]['dbName'] ?? '');
$user = (string) ($env[0]['userName'] ?? '');

echo "APP_ENV={$settings->appEnv}\n";
echo "SERVER={$server}\n";
echo "DB={$database}\n";
echo "USER={$user}\n";

if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: expected tricab-mel / tc, got {$server} / {$database}\n");
    exit(4);
}

$script = $basePath . '/database/scripts/development/create-quote-write-idempotency-schema.sql';
passthru('php ' . escapeshellarg($basePath . '/bin/run-sql-script.php') . ' ' . escapeshellarg($script), $code);
if ($code !== 0) {
    fwrite(STDERR, "QUOTE_IDEMPOTENCY_SCHEMA_FAILED\n");
    exit($code);
}

$check = $db->fetchAll("
SELECT CASE WHEN OBJECT_ID(N'[quote].[QuoteWriteIdempotency]', N'U') IS NULL THEN 0 ELSE 1 END AS ok
");
$ok = (int) ($check[0]['ok'] ?? 0) === 1;
echo $ok ? "QUOTE_IDEMPOTENCY_SCHEMA_APPLIED\n" : "QUOTE_IDEMPOTENCY_SCHEMA_FAILED\n";
exit($ok ? 0 : 1);
