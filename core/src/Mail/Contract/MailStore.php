<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Contract;

use DateTimeImmutable;
use TriCab\Core\Mail\Application\DTO\AttachmentRef;
use TriCab\Core\Mail\Application\DTO\ExportQuery;
use TriCab\Core\Mail\Application\DTO\ExportRow;
use TriCab\Core\Mail\Application\DTO\MailboxView;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\MessageRef;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\NewDecision;
use TriCab\Core\Mail\Application\DTO\NewLink;
use TriCab\Core\Mail\Application\DTO\NewMessage;
use TriCab\Core\Mail\Application\DTO\RecordedMessage;
use TriCab\Core\Mail\Application\DTO\ThreadPage;
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Application\DTO\ThreadRecord;

/**
 * The `[mail]` tables. Every method that changes anything is atomic: the rows, the activity line
 * and the thread's projection (see `ThreadProjector`) are written together or not at all.
 *
 * Queues. `all` is every classified thread; `marked` the threads with a label decision;
 * `skipped` the threads the pre-filter skipped; each label queue the threads whose
 * *effective* label (the decision's, else the classifier's) it names, skipped ones left out.
 * A thread nobody has classified yet is in no queue.
 */
interface MailStore
{
    /** @return list<MailboxView> */
    public function mailboxes(): array;

    /** @return array<string, array{total: int, unread: int}> keyed by every queue id */
    public function queueCounts(): array;

    public function listThreads(ThreadQuery $query): ThreadPage;

    /**
     * One row per classified thread: its current assessment, its message and its latest decision, oldest
     * received first. A thread nobody has assessed is left out.
     *
     * @return list<ExportRow>
     */
    public function exportRows(ExportQuery $query): array;

    public function thread(string $threadId): ?ThreadRecord;

    public function messageRef(string $messageId): ?MessageRef;

    public function attachmentRef(string $attachmentId): ?AttachmentRef;

    /** Marks every message of the thread read or unread. Our own state: nothing is written to Exchange. */
    public function markRead(string $threadId, bool $read): void;

    /** Appends the decision and its activity line; the latest decision wins. */
    public function recordDecision(string $threadId, NewDecision $decision): void;

    /** Links the draft, moves the thread from open to drafted and records `draft_created`. */
    public function recordDraft(string $threadId, NewLink $link, MailParty $actor): void;

    /** Moves the thread from open to ignored and records `ignored`. */
    public function recordIgnore(string $threadId, MailParty $actor, int $userId, ?string $note, DateTimeImmutable $at): void;

    // --- written by the reader and the classifier (plan 2.5) ---

    /** The mailbox's id, creating the row on first sight. */
    public function ensureMailbox(string $address, string $displayName): string;

    public function recordSync(string $mailboxId, string $connection, DateTimeImmutable $at): void;

    /** Inserts the message and its attachments, or does nothing when its internetMessageId is already there. */
    public function upsertMessage(string $mailboxId, NewMessage $message): RecordedMessage;

    /** The id of a message already recorded under this internetMessageId, if any. */
    public function findMessageId(string $mailboxId, string $internetMessageId): ?string;

    /** Appends an assessment to the message's history and refreshes the thread. */
    public function appendAssessment(string $messageId, NewAssessment $assessment): void;
}
