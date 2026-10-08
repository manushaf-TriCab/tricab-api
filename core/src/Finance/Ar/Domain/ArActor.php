<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/**
 * The signed-in person, as the Tri-AR use cases need them: the platform user id (decision
 * D7), the name and address a record keeps (the Bin shows who deleted a record), and the
 * platform permission codes they hold.
 */
final readonly class ArActor
{
    /**
     * @param list<string> $permissions platform codes (`FINANCE.AR.RECORDS.READ`)
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

    /** What a record keeps as "who": the address, as the source's `deleted_by` did, else the name. */
    public function who(): string
    {
        return $this->email !== '' ? strtolower($this->email) : $this->name;
    }
}
