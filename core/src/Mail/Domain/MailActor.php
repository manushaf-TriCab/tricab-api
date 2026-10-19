<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/** Who is asking, with the permissions they hold. Nothing else about them matters here. */
final readonly class MailActor
{
    /** @param list<string> $permissions */
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public array $permissions,
    ) {
    }

    public static function fromUser(AuthenticatedUser $user): self
    {
        return new self($user->userId, $user->displayName, $user->emailAddress, $user->permissions);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
