<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$settings = Environment::settings(dirname(__DIR__));
$container = ContainerFactory::create($settings);
/** @var SqlConnection $connection */
$connection = $container->get(SqlConnection::class);

$sql = file_get_contents(dirname(__DIR__) . '/database/scripts/development/create-legacy-migration-schema.sql');
if ($sql === false) {
    fwrite(STDERR, "Schema script not found.\n");
    exit(1);
}

$statements = preg_split('/\s*GO\s*/i', $sql) ?: [];
$applied = 0;
foreach ($statements as $statement) {
    $trimmed = trim($statement);
    if ($trimmed === '') {
        continue;
    }
    $connection->execute($trimmed);
    ++$applied;
}

echo "Legacy migration schema applied ($applied statements).\n";
