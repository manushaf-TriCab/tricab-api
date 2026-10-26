<?php

declare(strict_types=1);

/**
 * Keep the platform's copy of payroll's employee list current (HR employees
 * plan 1.4-1.5). See {@see \TriCab\Api\Hr\Employees\HrEmployeeSync} for the
 * rule: a read-only mirror of tc.employee and tc.dept_manager into [hr],
 * upserted by employee code, never deleted, never written back.
 *
 *   php bin/sync-hr-employees.php            # dry run: what a sync would do, writes nothing
 *   php bin/sync-hr-employees.php --apply    # sync
 *
 * Options: --env=FILE reads settings (DB_*, LEGACY_TC_*) from that dotenv file
 * instead of the repo's .env; --if-enabled does nothing (exit 3) unless
 * HR_EMPLOYEE_SYNC_ENABLED=true, for the `hr-employee-sync` WebJob.
 *
 * The target is the API's own database connection (managed identity on App
 * Service, the Azure CLI locally); the source is LEGACY_TC_*, SELECT only.
 *
 * Prints one JSON line of counts (never an employee's values). Exit codes: 0
 * done (or nothing to do), 1 failure, 2 refused (wrong server, or tc returned
 * no rows), 3 switched off, 5 another sync holds the lock, 75 the database is
 * resuming.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Config\Settings;
use TriCab\Api\Hr\Employees\HrEmployeeSync;
use TriCab\Api\Hr\Employees\HrEmployeeSyncBusy;
use TriCab\Api\Hr\Employees\HrEmployeeSyncRefused;
use TriCab\Api\Hr\Employees\MysqlHrEmployeeSource;
use TriCab\Api\Hr\Employees\SqlServerHrEmployeeStore;
use TriCab\Api\Hr\Employees\StaffMappingUserLoginLinker;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

CoreAutoload::register();

$options = getopt('', ['apply', 'env:', 'if-enabled']);
$apply = isset($options['apply']);

$say = static function (array $line): void {
    fwrite(STDOUT, json_encode(['at' => gmdate('c')] + $line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
};

if (isset($options['env'])) {
    $file = (string) $options['env'];
    if (!is_readable($file)) {
        fwrite(STDERR, "Cannot read --env file {$file}.\n");
        exit(1);
    }
    $env = array_merge($_SERVER, $_ENV, Dotenv::parse((string) file_get_contents($file)));
} else {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    $env = array_merge($_ENV, $_SERVER);
}

if (isset($options['if-enabled']) && strtolower(trim((string) ($env['HR_EMPLOYEE_SYNC_ENABLED'] ?? ''))) !== 'true') {
    exit(3);
}

$legacy = LiveLegacySourceConfig::fromEnvironment($env);
if (!$legacy->tcConfigured()) {
    fwrite(STDERR, "LEGACY_TC_* is not configured.\n");
    exit(1);
}

$source = null;
try {
    $settings = Settings::fromEnvironment($env);
    $factory = ContainerFactory::create($settings)->get(AzureSqlPdoFactory::class);
    if (!$factory instanceof AzureSqlPdoFactory) {
        throw new RuntimeException('The container has no Azure SQL connection.');
    }
    $pdo = $factory->create();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::SQLSRV_ATTR_ENCODING, PDO::SQLSRV_ENCODING_UTF8);
    $hr = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[hr].[Employees]', N'U') IS NULL THEN 0 ELSE 1 END");
    if ($hr === false || (int) $hr->fetchColumn() !== 1) {
        throw new HrEmployeeSyncRefused('[hr].[Employees] is not on this database. Wrong server, or the schema script has not run.');
    }

    $source = MysqlHrEmployeeSource::open($legacy);
    $sync = new HrEmployeeSync(new SqlServerHrEmployeeStore($pdo), new StaffMappingUserLoginLinker($pdo));
    $say($sync->refresh($source, $apply));
    exit(0);
} catch (HrEmployeeSyncRefused $e) {
    $say(['refused' => $e->getMessage()]);
    exit(2);
} catch (HrEmployeeSyncBusy $e) {
    $say(['busy' => $e->getMessage()]);
    exit(5);
} catch (DatabaseTemporarilyUnavailable) {
    $say(['resuming' => 'The database is resuming; the next pass retries.']);
    exit(75);
} catch (Throwable $e) {
    // Never the DSN (it can carry a token), and never a row's values.
    $say(['failed' => get_class($e) . ': ' . $e->getMessage()]);
    exit(1);
} finally {
    $source?->close();
}
