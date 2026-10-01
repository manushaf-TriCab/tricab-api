<?php

declare(strict_types=1);

use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\CustomerContactReport\Persistence\CustomerContactE2ePaths;

/**
 * Deletes the local Customer Contact browser-harness files.
 * Does not connect to Azure SQL and does not expose an HTTP route.
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';
CoreAutoload::register();

$root = CustomerContactE2ePaths::root();
CustomerContactE2ePaths::reset();
fwrite(STDOUT, "Customer Contact E2E data cleared from {$root}\n");
