<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Handler;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Resolves the current platform user by email for Core-layer verification
 * and application flows that already know the account email.
 */
final class GetCurrentUserUseCase
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @throws UserNotFound
     * @throws AuthenticationNotAllowed
     */
    public function execute(string $email): AuthenticatedUser
    {
        $emailAddress = EmailAddress::fromString($email);
        $user = $this->users->findByEmail($emailAddress);

        if ($user === null) {
            throw UserNotFound::forEmail($emailAddress->toString());
        }

        $user->assertAuthenticationAllowed();

        return AuthenticatedUser::fromUser($user);
    }
}
