<?php

declare(strict_types=1);

/**
 * Standalone Core smoke script (not a PHPUnit test case).
 *
 * Usage:
 *   php tests/SmokeTest.php
 */

use TriCab\Core\Identity\Application\Handler\GetCurrentUserUseCase;
use TriCab\Core\Shared\Package;
use TriCab\Core\Tests\Identity\Doubles\FakeUserRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$repository = new FakeUserRepository();
$useCase = new GetCurrentUserUseCase($repository);

$result = $useCase->execute('thilanh@tricab.com');

$payload = [
    'success' => true,
    'message' => 'TriCab Core is working',
    'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    'phpVersion' => PHP_VERSION,
    'coreVersion' => Package::NAME,
    'useCase' => GetCurrentUserUseCase::class,
    'repository' => FakeUserRepository::class,
    'fakeUser' => $repository->toSmokeArray(),
    'authenticatedUser' => [
        'userId' => $result->userId,
        'publicId' => $result->publicId,
        'emailAddress' => $result->emailAddress,
        'displayName' => $result->displayName,
        'status' => $result->status,
        'roles' => $result->roles,
        'permissions' => $result->permissions,
    ],
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(0);
