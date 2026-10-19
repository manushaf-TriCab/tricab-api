<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\Service;

use TriCab\Core\Mail\Application\DTO\MailboxView;
use TriCab\Core\Mail\Application\DTO\ThreadDetail;
use TriCab\Core\Mail\Application\DTO\ThreadPage;
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\Service\MailAccessPolicy;

/**
 * The inbox's reads: what this person may do, the queue counts, the thread list and one thread.
 * The routes hold `MAIL.ACCESS`; nothing here is scoped further (everyone sees every thread).
 */
final class MailInboxService
{
    public function __construct(private readonly MailStore $store)
    {
    }

    /** @return array<string, mixed> `MailMe` */
    public function me(MailActor $actor): array
    {
        return [
            'canReview' => MailAccessPolicy::canReview($actor),
            'canExport' => MailAccessPolicy::canExport($actor),
            'mailboxes' => array_map(static fn (MailboxView $m): array => $m->toArray(), $this->store->mailboxes()),
        ];
    }

    /** @return array<string, array{total: int, unread: int}> `MailQueueCounts` */
    public function queues(): array
    {
        return $this->store->queueCounts();
    }

    public function threads(ThreadQuery $query): ThreadPage
    {
        return $this->store->listThreads($query);
    }

    public function thread(MailActor $actor, string $threadId): ThreadDetail
    {
        $record = $this->store->thread($threadId);
        if ($record === null) {
            throw new MailNotFound('That conversation was not found.');
        }
        $guard = MailReviewGuard::evaluate($record->summary, $record->assessment, $actor);

        return new ThreadDetail($record, $guard['allowed'], $guard['blockers']);
    }
}
