<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$settings = Environment::settings(dirname(__DIR__));
$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

foreach (['LegacyMigrationBatches', 'LegacyMigrationLogs', 'LegacyStaffUserMappings'] as $table) {
    try {
        $rows = $db->fetchAll(
            "SELECT OBJECT_ID(N'migration.{$table}', N'U') AS [ObjectId]",
        );
        echo $table . '=' . ($rows[0]['ObjectId'] ?? 'null') . PHP_EOL;
    } catch (Throwable $e) {
        echo $table . '=ERROR ' . $e->getMessage() . PHP_EOL;
    }
}
