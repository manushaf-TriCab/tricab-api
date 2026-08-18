<?php

declare(strict_types=1);

use TriCab\Api\Bootstrap\AppBuilder;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;

$basePath = dirname(__DIR__) . '/app';

require $basePath . '/vendor/autoload.php';

$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);
$app = AppBuilder::build($container);

$app->run();
