<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

/** A person on a request (manager, TSD owner, signatory, actor): the identity user plus the name and email taken at the time. */
final readonly class NprPerson
{
    public function __construct(
        public int $userId,
        public string $email,
        public string $name,
    ) {
    }

    public function is(int $userId): bool
    {
        return $this->userId === $userId;
    }
}
