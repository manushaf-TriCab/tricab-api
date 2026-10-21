<?php

declare(strict_types=1);

use TriCab\Api\Bootstrap\AppBuilder;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;

ini_set('display_errors', '0');

require dirname(__DIR__) . '/vendor/autoload.php';

\TriCab\Api\Bootstrap\CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$container = ContainerFactory::create(
    $settings,
    hydratePlatformSettings: !in_array($requestPath, ['/health', '/health/database'], true),
);
$app = AppBuilder::build($container);

$app->run();
