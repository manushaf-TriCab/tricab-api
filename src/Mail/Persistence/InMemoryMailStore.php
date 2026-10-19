<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Persistence;

use DateTimeImmutable;
use TriCab\Core\Mail\Application\DTO\ActivityView;
use TriCab\Core\Mail\Application\DTO\AssessmentView;
use TriCab\Core\Mail\Application\DTO\AttachmentRef;
use TriCab\Core\Mail\Application\DTO\AttachmentView;
use TriCab\Core\Mail\Application\DTO\DecisionView;
use TriCab\Core\Mail\Application\DTO\ExportQuery;
use TriCab\Core\Mail\Application\DTO\ExportRow;
use TriCab\Core\Mail\Application\DTO\LinkView;
use TriCab\Core\Mail\Application\DTO\MailBlocker;
use TriCab\Core\Mail\Application\DTO\MailboxView;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\MessageRef;
use TriCab\Core\Mail\Application\DTO\MessageView;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\NewDecision;
use TriCab\Core\Mail\Application\DTO\NewLink;
use TriCab\Core\Mail\Application\DTO\NewMessage;
use TriCab\Core\Mail\Application\DTO\RecordedMessage;
use TriCab\Core\Mail\Application\DTO\ThreadPage;
use TriCab\Core\Mail\Application\DTO\ThreadProjection;
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Application\DTO\ThreadRecord;
use TriCab\Core\Mail\Application\DTO\ThreadSummary;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailActionBlocked;
use TriCab\Core\Mail\Domain\MailIds;
use TriCab\Core\Mail\Domain\MailVocabulary as V;
use TriCab\Core\Mail\Domain\Service\ThreadProjector;
use TriCab\Core\Mail\Domain\Service\ThreadQueues;

/**
 * `[mail]` in memory: the default where there is no database, and the tests' store. It keeps the same
 * rows the SQL store does and projects each thread with the same {@see ThreadProjector}.
 */
final class InMemoryMailStore implements MailStore
{
    /** @var array<string, array{id: string, address: string, displayName: string, connection: string, lastSyncAt: ?DateTimeImmutable}> */
    private array $mailboxes = [];

    /** @var array<string, array{id: string, mailboxId: string, conversationId: string, subject: string, reviewState: string, deletedAt: ?DateTimeImmutable}> */
    private array $threads = [];

    /** @var array<string, array{view: MessageView, mailboxId: string, threadId: string, graphId: string}> */
    private array $messages = [];

    /** @var array<string, array{view: AttachmentView, messageId: string, graphId: string}> */
    private array $attachments = [];

    /** @var list<AssessmentView> */
    private array $assessments = [];

    /** @var list<array{threadId: string, view: DecisionView}> */
    private array $decisions = [];

    /** @var list<array{threadId: string, view: ActivityView}> */
    private array $activity = [];

    /** @var list<array{threadId: string, view: LinkView}> */
    private array $links = [];

    /** @var array<string, ThreadProjection> */
    private array $projections = [];

    public function mailboxes(): array
    {
        $out = [];
        foreach ($this->mailboxes as $m) {
            $out[] = new MailboxView($m['id'], $m['address'], $m['displayName'], $m['connection'], $m['lastSyncAt']);
        }
        usort($out, static fn (MailboxView $a, MailboxView $b): int => strcmp($a->address, $b->address));

        return $out;
    }

    public function queueCounts(): array
    {
        $counts = array_fill_keys(V::QUEUES, ['total' => 0, 'unread' => 0]);
        foreach ($this->summaries() as $summary) {
            foreach (V::QUEUES as $queue) {
                if (ThreadQueues::contains($summary, $queue)) {
                    ++$counts[$queue]['total'];
                    if ($summary->unread) {
                        ++$counts[$queue]['unread'];
                    }
                }
            }
        }

        return $counts;
    }

    public function listThreads(ThreadQuery $query): ThreadPage
    {
        $rows = array_values(array_filter($this->summaries(), static fn (ThreadSummary $t): bool => ThreadQueues::contains($t, $query->queue)
            && (!$query->unreadOnly || $t->unread)
            && ($query->attachments === 'any' || $t->hasAttachments === ($query->attachments === 'with'))
            && ThreadQueues::matches($t, $query->search)));
        usort($rows, ThreadQueues::comparator($query->sort));
        $items = array_slice($rows, $query->offset, $query->pageSize);
        $next = $query->offset + count($items);

        return new ThreadPage($items, $next < count($rows) ? ThreadQuery::encodeCursor($next) : null, count($rows));
    }

    public function exportRows(ExportQuery $query): array
    {
        $rows = [];
        foreach ($this->projections as $threadId => $projection) {
            $assessment = $projection->assessmentId === null ? null : $this->assessmentById($projection->assessmentId);
            if (!$projection->classified || $assessment === null || $this->deleted($threadId)) {
                continue;
            }
            $message = $this->messages[$assessment->messageId]['view'];
            $decision = null;
            foreach ($this->decisions as $d) {
                if ($d['view']->id === $projection->decisionId) {
                    $decision = $d['view'];
                }
            }
            if (($query->decidedOnly && $decision === null)
                || ($query->from !== null && $message->receivedAt < $query->from)
                || ($query->to !== null && $message->receivedAt > $query->to)) {
                continue;
            }
            $rows[] = new ExportRow(
                $threadId,
                $message->internetMessageId,
                $message->receivedAt,
                ExportRow::domainOf($message->from->address),
                $assessment->label,
                $assessment->confidence,
                $assessment->model,
                $assessment->version,
                $assessment->skipped,
                $assessment->injectionSuspected,
                $decision?->label,
                $decision?->kind,
                $decision?->decidedAt,
                $decision?->decidedBy->name,
            );
        }
        usort($rows, static fn (ExportRow $a, ExportRow $b): int => [$a->receivedAt, $a->threadId] <=> [$b->receivedAt, $b->threadId]);

        return $rows;
    }

    public function thread(string $threadId): ?ThreadRecord
    {
        $projection = $this->projections[$threadId] ?? null;
        if ($projection === null || !$projection->classified || $this->deleted($threadId)) {
            return null;
        }
        $summary = $projection->summary;
        $messages = $this->messagesOf($threadId);
        $assessment = null;
        foreach ($this->assessments as $a) {
            if ($a->id === $projection->assessmentId) {
                $assessment = $a;
            }
        }
        if ($assessment === null) {
            return null;
        }
        $decision = null;
        foreach ($this->decisions as $d) {
            if ($d['view']->id === $projection->decisionId) {
                $decision = $d['view'];
            }
        }
        $activity = array_map(static fn (array $a): ActivityView => $a['view'], array_values(array_filter($this->activity, static fn (array $a): bool => $a['threadId'] === $threadId)));
        usort($activity, static fn (ActivityView $a, ActivityView $b): int => $a->at <=> $b->at);
        $links = array_map(static fn (array $l): LinkView => $l['view'], array_values(array_filter($this->links, static fn (array $l): bool => $l['threadId'] === $threadId)));

        return new ThreadRecord($summary, $messages, $assessment, $decision, $activity, $links);
    }

    public function messageRef(string $messageId): ?MessageRef
    {
        $m = $this->messages[$messageId] ?? null;

        return $m === null || $this->deleted($m['threadId']) ? null : new MessageRef($messageId, $this->mailboxes[$m['mailboxId']]['address'], $m['graphId']);
    }

    public function attachmentRef(string $attachmentId): ?AttachmentRef
    {
        $a = $this->attachments[$attachmentId] ?? null;
        if ($a === null) {
            return null;
        }
        $m = $this->messages[$a['messageId']];
        if ($this->deleted($m['threadId'])) {
            return null;
        }

        return new AttachmentRef($attachmentId, $this->mailboxes[$m['mailboxId']]['address'], $m['graphId'], $a['graphId'], $a['view']->fileName, $a['view']->mimeType, $a['view']->sizeBytes);
    }

    public function markRead(string $threadId, bool $read): void
    {
        foreach ($this->messages as $id => $m) {
            if ($m['threadId'] === $threadId) {
                $v = $m['view'];
                $this->messages[$id]['view'] = new MessageView($v->id, $v->internetMessageId, $v->from, $v->to, $v->cc, $v->receivedAt, $v->preview, $v->attachments, $read);
            }
        }
        $this->refresh($threadId);
    }

    public function recordDecision(string $threadId, NewDecision $decision): void
    {
        $view = new DecisionView(MailIds::new(), $decision->assessmentId, $decision->kind, $decision->label, $decision->decidedBy, $decision->decidedAt, $decision->note);
        $this->decisions[] = ['threadId' => $threadId, 'view' => $view];
        $assessed = $this->assessmentById($decision->assessmentId)->label ?? $decision->label;
        $note = $decision->note !== null ? ' ' . $decision->note : '';
        $this->note($threadId, $decision->kind === 'confirm' ? V::ACTIVITY_LABEL_CONFIRMED : V::ACTIVITY_LABEL_CORRECTED,
            $decision->kind === 'confirm'
                ? sprintf('Confirmed the label: %s.%s', V::labelWord($assessed), $note)
                : sprintf('Corrected the label from %s to %s.%s', V::labelWord($assessed), V::labelWord($decision->label), $note),
            $decision->decidedAt,
            $decision->decidedBy,
        );
        $this->refresh($threadId);
    }

    public function recordDraft(string $threadId, NewLink $link, MailParty $actor): void
    {
        $this->requireOpen($threadId);
        $this->links[] = ['threadId' => $threadId, 'view' => new LinkView($link->publicId, $link->reference, $link->title, $link->createdAt)];
        $this->threads[$threadId]['reviewState'] = V::STATE_DRAFTED;
        $this->note($threadId, V::ACTIVITY_DRAFT_CREATED, sprintf('Created RFQ draft %s.', $link->reference), $link->createdAt, $actor);
        $this->refresh($threadId);
    }

    public function recordIgnore(string $threadId, MailParty $actor, int $userId, ?string $note, DateTimeImmutable $at): void
    {
        $this->requireOpen($threadId);
        $this->threads[$threadId]['reviewState'] = V::STATE_IGNORED;
        $this->note($threadId, V::ACTIVITY_IGNORED, $note !== null ? 'Ignored: ' . $note : 'Ignored.', $at, $actor);
        $this->refresh($threadId);
    }

    public function deleteThread(string $threadId, MailParty $actor, int $userId, DateTimeImmutable $at): void
    {
        if (isset($this->threads[$threadId]) && !$this->deleted($threadId)) {
            $this->threads[$threadId]['deletedAt'] = $at;
        }
    }

    public function ensureMailbox(string $address, string $displayName): string
    {
        foreach ($this->mailboxes as $m) {
            if ($m['address'] === $address) {
                return $m['id'];
            }
        }
        $id = MailIds::new();
        $this->mailboxes[$id] = ['id' => $id, 'address' => $address, 'displayName' => $displayName, 'connection' => 'connected', 'lastSyncAt' => null];

        return $id;
    }

    public function recordSync(string $mailboxId, string $connection, DateTimeImmutable $at): void
    {
        $m = $this->mailboxes[$mailboxId];
        $this->mailboxes[$mailboxId] = ['id' => $m['id'], 'address' => $m['address'], 'displayName' => $m['displayName'], 'connection' => $connection, 'lastSyncAt' => $at];
    }

    public function findMessageId(string $mailboxId, string $internetMessageId): ?string
    {
        foreach ($this->messages as $id => $m) {
            if ($m['mailboxId'] === $mailboxId && $m['view']->internetMessageId === $internetMessageId) {
                return $id;
            }
        }

        return null;
    }

    public function upsertMessage(string $mailboxId, NewMessage $message): RecordedMessage
    {
        $existing = $this->findMessageId($mailboxId, $message->internetMessageId);
        if ($existing !== null) {
            return new RecordedMessage($existing, $this->messages[$existing]['threadId'], false);
        }
        $conversation = $message->conversationId !== '' ? $message->conversationId : 'internet:' . $message->internetMessageId;
        $threadId = null;
        foreach ($this->threads as $t) {
            if ($t['mailboxId'] === $mailboxId && $t['conversationId'] === $conversation) {
                $threadId = $t['id'];
            }
        }
        if ($threadId === null) {
            $threadId = MailIds::new();
            $this->threads[$threadId] = ['id' => $threadId, 'mailboxId' => $mailboxId, 'conversationId' => $conversation, 'subject' => $message->subject, 'reviewState' => V::STATE_OPEN, 'deletedAt' => null];
        } else {
            // A new message in a deleted conversation brings it back (a redelivered one returned above).
            $this->threads[$threadId]['deletedAt'] = null;
        }

        $messageId = MailIds::new();
        $listed = [];
        foreach ($message->attachments as $a) {
            $attachmentId = MailIds::new();
            $view = new AttachmentView($attachmentId, $messageId, $a->fileName, $a->mimeType, $a->sizeBytes);
            $this->attachments[$attachmentId] = ['view' => $view, 'messageId' => $messageId, 'graphId' => $a->graphAttachmentId];
            if (!$a->isInline) {
                $listed[] = $view;
            }
        }
        $this->messages[$messageId] = [
            'view' => new MessageView($messageId, $message->internetMessageId, $message->from, $message->to, $message->cc, $message->receivedAt, $message->bodyPreview, $listed, $message->isRead),
            'mailboxId' => $mailboxId,
            'threadId' => $threadId,
            'graphId' => $message->graphMessageId,
        ];
        $this->note($threadId, V::ACTIVITY_RECEIVED, sprintf('Message received from %s.', $message->from->name !== '' ? $message->from->name : $message->from->address), $message->receivedAt, null);
        $this->refresh($threadId);

        return new RecordedMessage($messageId, $threadId, true);
    }

    public function appendAssessment(string $messageId, NewAssessment $assessment): void
    {
        $this->assessments[] = new AssessmentView(
            MailIds::new(),
            $messageId,
            $assessment->label,
            $assessment->confidence,
            $assessment->reason,
            $assessment->model,
            $assessment->latencyMs,
            $assessment->version,
            $assessment->assessedAt,
            $assessment->skipped,
            $assessment->injectionSuspected,
        );
        $threadId = $this->messages[$messageId]['threadId'];
        $this->note($threadId, $assessment->skipped ? V::ACTIVITY_SKIPPED : V::ACTIVITY_CLASSIFIED,
            $assessment->skipped ? $assessment->reason : sprintf('Classified as %s (%d%%).', V::labelWord($assessment->label), (int) round($assessment->confidence * 100)),
            $assessment->assessedAt,
            null,
        );
        $this->refresh($threadId);
    }

    // --- internals ---

    /** @return list<ThreadSummary> */
    private function summaries(): array
    {
        $out = [];
        foreach ($this->projections as $threadId => $projection) {
            if ($projection->classified && !$this->deleted($threadId)) {
                $out[] = $projection->summary;
            }
        }

        return $out;
    }

    /** @return list<MessageView> oldest first */
    private function messagesOf(string $threadId): array
    {
        $out = [];
        foreach ($this->messages as $m) {
            if ($m['threadId'] === $threadId) {
                $out[] = $m['view'];
            }
        }
        usort($out, static fn (MessageView $a, MessageView $b): int => $a->receivedAt <=> $b->receivedAt);

        return $out;
    }

    private function assessmentById(string $id): ?AssessmentView
    {
        foreach ($this->assessments as $a) {
            if ($a->id === $id) {
                return $a;
            }
        }

        return null;
    }

    private function deleted(string $threadId): bool
    {
        return ($this->threads[$threadId]['deletedAt'] ?? null) !== null;
    }

    private function requireOpen(string $threadId): void
    {
        if (($this->threads[$threadId]['reviewState'] ?? null) !== V::STATE_OPEN) {
            throw new MailActionBlocked([new MailBlocker(V::BLOCKER_ALREADY_ACTIONED, 'This conversation has already been actioned.')]);
        }
    }

    private function note(string $threadId, string $kind, string $description, DateTimeImmutable $at, ?MailParty $actor): void
    {
        $this->activity[] = ['threadId' => $threadId, 'view' => new ActivityView(MailIds::new(), $at, $actor, $kind, $description)];
    }

    private function refresh(string $threadId): void
    {
        $thread = $this->threads[$threadId];
        $messages = $this->messagesOf($threadId);
        $ids = array_map(static fn (MessageView $m): string => $m->id, $messages);
        $this->projections[$threadId] = ThreadProjector::project(
            $threadId,
            $thread['mailboxId'],
            $this->mailboxes[$thread['mailboxId']]['address'],
            $thread['subject'],
            $thread['reviewState'],
            $messages,
            array_values(array_filter($this->assessments, static fn (AssessmentView $a): bool => in_array($a->messageId, $ids, true))),
            array_map(static fn (array $d): DecisionView => $d['view'], array_values(array_filter($this->decisions, static fn (array $d): bool => $d['threadId'] === $threadId))),
        );
    }
}
