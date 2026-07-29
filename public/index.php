<?php

declare(strict_types=1);

use TriCab\Api\Bootstrap\AppBuilder;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);
$app = AppBuilder::build($container);

$app->run();
