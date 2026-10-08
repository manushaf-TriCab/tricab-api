<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/**
 * Someone a record can be assigned to (decision D43): an active platform user holding
 * `FINANCE.AR.RECORDS.WRITE` through an active role. The portal's `list_members_with_permission`.
 */
final readonly class ArAssignee
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
    ) {
    }

    /** The name a record shows: the full name, else the address (what the pages used as the option value). */
    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : strtolower($this->email);
    }

    /**
     * The source's `{email, full_name}`, plus the platform id as text (ids are text in the API, as in Tri-Task).
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['id' => (string) $this->userId, 'email' => strtolower($this->email), 'full_name' => $this->displayName()];
    }
}
