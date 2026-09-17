<?php

declare(strict_types=1);

/**
 * NON-PRODUCTION: create tc.quote_write_correlation_dev using WRITE credentials.
 *
 * Does NOT create operational quote_write_correlation (empty suffix).
 * Does NOT INSERT into tqoth_dev / tqoti_dev / tqote_dev.
 *
 * Requires QUOTES_ORDERS_LEGACY_WRITE_* (never LEGACY_TC_*).
 *
 * Usage:
 *   php bin/ensure-quote-write-correlation-dev.php
 */

use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$config = $settings->legacyQuoteWriteConfig();
if (!$config->isConfigured()) {
    fwrite(STDERR, "WRITE_CREDENTIALS_MISSING: set QUOTES_ORDERS_LEGACY_WRITE_* (not LEGACY_TC_*)\n");
    exit(2);
}

if (($config->tableSuffix ?? '') !== '_dev' && $config->tableSuffixConfigured) {
    // Allow apply when suffix unset yet, but never when explicitly non-_dev.
    if ($config->tableSuffixConfigured && (string) $config->tableSuffix !== '_dev') {
        fwrite(STDERR, "STOP: correlation ensure is _dev-only\n");
        exit(4);
    }
}

$dsn = (string) $config->dsn;
$user = (string) $config->user;
$pass = (string) $config->password;

$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (strtolower((string) ($config->sslMode ?? '')) === 'required') {
    $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $opts);
} catch (Throwable $e) {
    fwrite(STDERR, "CONNECT_FAIL\n");
    exit(3);
}

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "APP_ENV={$settings->appEnv}\n";
echo "DATABASE={$dbName}\n";
echo "TARGET_TABLE=tc.quote_write_correlation_dev\n";

$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS tc.quote_write_correlation_dev (
  platform_operation_id CHAR(36) NOT NULL,
  quote_id BIGINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (platform_operation_id),
  UNIQUE KEY UX_quote_write_correlation_dev_quote_id (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

$pdo->beginTransaction();
try {
    $pdo->exec($sql);
    // Non-mutating verification inside same session.
    $st = $pdo->query(
        "SELECT COUNT(*) AS c FROM information_schema.tables"
        . " WHERE table_schema = DATABASE() AND table_name = 'quote_write_correlation_dev'",
    );
    $exists = (int) ($st->fetch()['c'] ?? 0) === 1;
    $pdo->rollBack(); // DDL may autocommit on MySQL; rollback is best-effort for any DML
    if (!$exists) {
        // Re-check after possible DDL autocommit
        $st = $pdo->query(
            "SELECT COUNT(*) AS c FROM information_schema.tables"
            . " WHERE table_schema = DATABASE() AND table_name = 'quote_write_correlation_dev'",
        );
        $exists = (int) ($st->fetch()['c'] ?? 0) === 1;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "DDL_FAIL\n");
    exit(5);
}

// Prove SELECT path without quote rows
$probe = $pdo->prepare(
    'SELECT quote_id FROM tc.quote_write_correlation_dev WHERE platform_operation_id = :op LIMIT 1',
);
$probe->execute(['op' => '00000000-0000-4000-8000-000000000000']);
$row = $probe->fetch();
echo $row === false ? "CORRELATION_LOOKUP_EMPTY_OK\n" : "CORRELATION_LOOKUP_UNEXPECTED\n";

echo $exists ? "QUOTE_WRITE_CORRELATION_DEV_READY\n" : "QUOTE_WRITE_CORRELATION_DEV_FAILED\n";
exit($exists ? 0 : 1);
