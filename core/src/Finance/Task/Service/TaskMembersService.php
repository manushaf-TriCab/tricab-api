<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;

/**
 * `GET /me`, `GET /members` and `PATCH /members/{id}/profile` (routes 5 to 7). Routes 8
 * and 9, which changed a member's role in the portal, are not built (question Q6): roles
 * belong to the platform's identity, so the role is read here and never written.
 */
final class TaskMembersService
{
    public const MAX_JOB_TITLE = 200;

    public function __construct(
        private readonly FinanceTaskMemberDirectory $members,
        private readonly TaskTime $time,
    ) {
    }

    /**
     * What the pages read about the signed-in person (decision D19): the portal's own
     * permission strings, derived from the platform codes by `Module::TASK_PORTAL_PERMISSIONS`,
     * so `useAccessRole` and `lib/permissions.js` work unchanged.
     *
     * @return array<string, mixed>
     */
    public function me(TaskActor $actor): array
    {
        $portal = [];
        foreach (Module::TASK_PORTAL_PERMISSIONS as $code => $string) {
            if ($actor->can($code)) {
                $portal[] = $string;
            }
        }
        sort($portal);

        return [
            'id' => (string) $actor->userId,
            'name' => $actor->name !== '' ? $actor->name : $actor->email,
            'full_name' => $actor->name !== '' ? $actor->name : $actor->email,
            'email' => strtolower($actor->email),
            'timezone' => $this->time->zoneName(),
            'accessRole' => self::accessRoleOf($actor),
            'permissions' => $portal,
        ];
    }

    /**
     * The highest Tri-Task role the permissions amount to: the same test the pages use.
     * `admin` reads analytics, `manager` reads every task, else `user`.
     */
    public static function accessRoleOf(TaskActor $actor): string
    {
        if ($actor->can(Module::PERMISSION_TASK_ANALYTICS_READ)) {
            return TaskMember::ROLE_ADMIN;
        }
        if ($actor->can(Module::PERMISSION_TASK_TASKS_READ_ALL)) {
            return TaskMember::ROLE_MANAGER;
        }

        return TaskMember::ROLE_USER;
    }

    /** @return list<array<string, string>> */
    public function list(): array
    {
        return array_map(static fn (TaskMember $m): array => $m->toArray(), $this->members->members());
    }

    /**
     * @return array<string, string> `{user_id, job_title}`
     */
    public function setJobTitle(int $userId, mixed $jobTitle): array
    {
        if ($this->members->find($userId) === null) {
            throw new TaskNotFound('Member not found');
        }
        $title = is_scalar($jobTitle) ? self::trim((string) $jobTitle) : '';
        if (mb_strlen($title) > self::MAX_JOB_TITLE) {
            throw new TaskInvalid('Job title must be at most ' . self::MAX_JOB_TITLE . ' characters');
        }
        $this->members->setJobTitle($userId, $title, $this->time->now());

        return ['user_id' => (string) $userId, 'job_title' => $title];
    }

    private static function trim(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? $value;
    }
}
