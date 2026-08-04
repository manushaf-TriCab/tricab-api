<?php

declare(strict_types=1);

/**
 * Dev diagnostic: reproduce listUsers + audit list failures with full exception text.
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditCriteria;
use TriCab\Core\Identity\Contract\UserManagementAuditRepository;
use TriCab\Core\Identity\Contract\UserManagementRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);

/** @var UserManagementRepository $users */
$users = $container->get(UserManagementRepository::class);
/** @var UserManagementAuditRepository $audit */
$audit = $container->get(UserManagementAuditRepository::class);

echo "=== summarizeCounts ===\n";
try {
    $counts = $users->summarizeCounts();
    echo json_encode($counts, JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo '  previous: ' . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage() . "\n";
    }
}

echo "\n=== listUsers ===\n";
try {
    $result = $users->listUsers(new UserListCriteria(page: 1, pageSize: 25, sortBy: 'createdAt', sortDirection: 'desc'));
    echo 'items=' . count($result->items) . ' total=' . $result->pagination->totalItems . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo '  previous: ' . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage() . "\n";
    }
}

echo "\n=== listAudit ===\n";
try {
    $result = $audit->list(new UserManagementAuditCriteria(page: 1, pageSize: 25));
    echo 'items=' . count($result->items) . ' total=' . $result->pagination->totalItems . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo '  previous: ' . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage() . "\n";
    }
}
