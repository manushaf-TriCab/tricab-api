<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Domain\TaskReportRecipient;

/**
 * Who gets the daily summary (routes 48 to 50): list, add (a unique email, linked to the member who holds that
 * address, as route 49 did), and a hard delete. Gated on `FINANCE.TASK.SETTINGS.MANAGE`.
 */
final class TaskReportRecipientService
{
    private const EMAIL = '/^[^@\s,;<>]+@[^@\s,;<>]+\.[^@\s,;<>]+$/u';

    public function __construct(
        private readonly TaskReportRecipientStore $store,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly TaskTime $time,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(self::dict(...), $this->store->all());
    }

    /**
     * @param array<string, mixed> $data `{email, name}`
     * @return array<string, mixed>
     */
    public function add(array $data): array
    {
        $raw = $data['email'] ?? '';
        $email = strtolower(TaskRules::strip(is_scalar($raw) && $raw !== false ? (string) $raw : ''));
        if (preg_match(self::EMAIL, $email) !== 1) {
            throw new TaskInvalid('Enter a valid email address');
        }
        if ($this->store->findByEmail($email) !== null) {
            throw new TaskConflict('That address is already a recipient');
        }
        $member = null;
        foreach ($this->members->members() as $candidate) {
            if (strtolower($candidate->email) === $email) {
                $member = $candidate;
                break;
            }
        }
        $given = $data['name'] ?? '';
        $name = TaskRules::strip(is_scalar($given) && $given !== false ? (string) $given : '');
        if ($name === '' && $member !== null) {
            $name = $member->name !== '' ? $member->name : $member->email;
        }
        $now = $this->time->now();
        $recipient = new TaskReportRecipient(TaskRules::newId(), $email, mb_substr($name, 0, 200), $member?->userId, $now, $now);
        $this->store->insert($recipient);

        return self::dict($recipient);
    }

    /** @return array<string, bool> */
    public function remove(string $id): array
    {
        if ($this->store->find($id) === null) {
            throw new TaskNotFound('Recipient not found');
        }
        $this->store->delete($id);

        return ['deleted' => true];
    }

    /** @return array<string, mixed> */
    private static function dict(TaskReportRecipient $r): array
    {
        return [
            'id' => $r->id,
            'email' => $r->email,
            'name' => $r->name,
            'platform_user_id' => TaskPresenter::person($r->userId),
            'created_date' => TaskPresenter::iso($r->createdDate),
        ];
    }
}
