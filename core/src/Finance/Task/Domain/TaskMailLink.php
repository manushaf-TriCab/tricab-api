<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * The thin link between a task and a row of the shared mail ledger (question Q5,
 * `finance.task_mail_links`). The subject, recipients and status are read from the ledger;
 * the body of a sent mail is kept nowhere. `taskId` has no foreign key, so a permanently
 * deleted task keeps its history.
 */
final readonly class TaskMailLink
{
    public const KIND_TASK_EMAIL = 'task_email';
    public const KIND_REMINDER = 'reminder';
    public const KIND_DAILY_SUMMARY = 'daily_summary';
    public const KIND_CALENDAR_INVITE = 'calendar_invite';

    public function __construct(
        public string $id,
        public string $kind,
        public ?string $taskId,
        public string $deliveryId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
