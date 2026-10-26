<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** The signed-in person, as the Tri-AP2 use cases need them: who they are and which permissions they hold. */
final readonly class Ap2Actor
{
    /**
     * @param list<string> $permissions platform codes (`FINANCE.AP2.INVOICES.READ`)
     */
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public array $permissions,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function person(): Ap2Person
    {
        return new Ap2Person($this->userId, $this->name, strtolower($this->email));
    }
}
