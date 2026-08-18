<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Handler;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Application\Handler\GetCurrentUserUseCase;
use TriCab\Core\Tests\Identity\Doubles\FakeUserRepository;

final class GetCurrentUserUseCaseTest extends TestCase
{
    public function test_returns_authenticated_user_for_known_email(): void
    {
        $useCase = new GetCurrentUserUseCase(new FakeUserRepository());

        $result = $useCase->execute('thilanh@tricab.com');

        self::assertSame(1, $result->userId);
        self::assertSame('thilanh@tricab.com', $result->emailAddress);
        self::assertSame('Thilan Lakshitha', $result->displayName);
        self::assertSame('active', $result->status);
        self::assertSame(['EMPLOYEE'], $result->roles);
    }

    public function test_rejects_unknown_email(): void
    {
        $useCase = new GetCurrentUserUseCase(new FakeUserRepository());

        $this->expectException(UserNotFound::class);

        $useCase->execute('missing@tricab.com');
    }
}
