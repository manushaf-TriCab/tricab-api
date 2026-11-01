<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** A platform user as Tri-AP shows them: `identity.Users.UserId` with the name and address held at the time. */
final readonly class Ap2Person
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
    ) {
    }

    /** @return array{userId: int, name: string, email: string} */
    public function toArray(): array
    {
        return ['userId' => $this->userId, 'name' => $this->name, 'email' => $this->email];
    }
}
