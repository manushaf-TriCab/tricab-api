<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/**
 * A person who can be given Tri-Task work: an active platform user holding
 * `FINANCE.TASK.TASKS.READ` through an active role (decision D20), with the
 * highest Tri-Task role they hold and the job title Tri-Task keeps for them.
 */
final readonly class TaskMember
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_USER = 'user';

    /** The portal's order: the first one held names the person. */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_USER];

    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public string $accessRole,
        public string $jobTitle,
    ) {
    }

    /** The shape `GET /members` answers, as the portal's `_members()` did (ids as text, see TaskPresenter). */
    /** @return array<string, string> */
    public function toArray(): array
    {
        $name = $this->name !== '' ? $this->name : $this->email;

        return [
            'id' => (string) $this->userId,
            'email' => strtolower($this->email),
            'full_name' => $name,
            'name' => $name,
            'job_title' => $this->jobTitle,
            'role' => $this->jobTitle,
            'access_role' => $this->accessRole,
        ];
    }
}
