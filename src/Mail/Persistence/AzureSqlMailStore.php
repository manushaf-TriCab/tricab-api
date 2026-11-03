<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Persistence;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
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
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Application\DTO\ThreadRecord;
use TriCab\Core\Mail\Application\DTO\ThreadSummary;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailActionBlocked;
use TriCab\Core\Mail\Domain\MailIds;
use TriCab\Core\Mail\Domain\MailVocabulary as V;
use TriCab\Core\Mail\Domain\Service\ThreadProjector;

/**
 * `[mail]` on Azure SQL (`create-mail-schema.sql`). Every write runs in one transaction with the thread's
 * projection: after any change to a thread's messages, assessments or decisions the thread is reloaded,
 * run through {@see ThreadProjector} and written back to the summary columns on `mail.threads`. Lists
 * and counts read only those columns.
 *
 * Queue rules are stated in {@see self::QUEUE_SQL} and mirror `ThreadQueues`; a thread with no
 * assessment has NULL label columns and is in no list, and a deleted one (`deleted_at`) is in none either.
 */
final class AzureSqlMailStore implements MailStore
{
    use AzureSqlTransaction;

    private const DUPLICATE_KEY_CODES = [2601, 2627];

    /** Every queue as a predicate over `mail.threads`. */
    private const QUEUE_SQL = [
        'all' => '1 = 1',
        'marked' => '[decision_id] IS NOT NULL',
        'skipped' => '[skipped] = 1',
        'uncertain' => "[skipped] = 0 AND [effective_label] = N'uncertain'",
        'rfq' => "[skipped] = 0 AND [effective_label] = N'rfq'",
        'rfq_followup' => "[skipped] = 0 AND [effective_label] = N'rfq_followup'",
        'not_rfq' => "[skipped] = 0 AND [effective_label] = N'not_rfq'",
    ];

    private const ORDER_SQL = [
        'latest' => '[latest_at] DESC, [id]',
        'oldest' => '[latest_at] ASC, [id]',
        'sender' => '[from_name] ASC, [latest_at] DESC, [id]',
        'confidence' => '[confidence] DESC, [latest_at] DESC, [id]',
    ];

    private const THREAD_COLUMNS = '[id], [mailbox_id], [subject], [from_name], [from_address], [preview], [latest_at], [message_count],
        [unread_count], [has_attachments], [label], [confidence], [effective_label], [decision_id], [skipped], [review_state]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function mailboxes(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [address], [display_name], [connection], [last_sync_at] FROM [mail].[mailboxes] ORDER BY [address]',
        );

        return array_map(static fn (array $r): MailboxView => new MailboxView(
            AzureSqlValueMapper::stringValue($r['id']),
            AzureSqlValueMapper::stringValue($r['address']),
            AzureSqlValueMapper::stringValue($r['display_name']),
            AzureSqlValueMapper::stringValue($r['connection']),
            AzureSqlValueMapper::nullableDateTime($r['last_sync_at']),
        ), $rows);
    }

    public function queueCounts(): array
    {
        $columns = [];
        foreach (self::QUEUE_SQL as $queue => $predicate) {
            $columns[] = sprintf('SUM(CASE WHEN %1$s THEN 1 ELSE 0 END) AS [%2$s_total]', $predicate, $queue);
            $columns[] = sprintf('SUM(CASE WHEN (%1$s) AND [unread_count] > 0 THEN 1 ELSE 0 END) AS [%2$s_unread]', $predicate, $queue);
        }
        $rows = $this->connection->fetchAll('SELECT ' . implode(', ', $columns) . ' FROM [mail].[threads] WHERE [label] IS NOT NULL AND [deleted_at] IS NULL');
        $row = $rows[0] ?? [];

        $counts = [];
        foreach (V::QUEUES as $queue) {
            $counts[$queue] = [
                'total' => AzureSqlValueMapper::intValue($row[$queue . '_total'] ?? 0),
                'unread' => AzureSqlValueMapper::intValue($row[$queue . '_unread'] ?? 0),
            ];
        }

        return $counts;
    }

    public function listThreads(ThreadQuery $query): ThreadPage
    {
        $where = ['[label] IS NOT NULL', '[deleted_at] IS NULL', '(' . self::QUEUE_SQL[$query->queue] . ')'];
        $params = [];
        if ($query->unreadOnly) {
            $where[] = '[unread_count] > 0';
        }
        if ($query->attachments !== 'any') {
            $where[] = '[has_attachments] = :has_attachments';
            $params['has_attachments'] = $query->attachments === 'with' ? 1 : 0;
        }
        if ($query->search !== '') {
            $like = [];
            foreach (['subject', 'from_name', 'from_address', 'preview'] as $i => $column) {
                // `!` is the escape character: PDO's parser reads a backslash before a quote as escaping it.
                $like[] = sprintf("[%s] LIKE :search%d ESCAPE '!'", $column, $i);
                $params['search' . $i] = '%' . strtr($query->search, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }
        $clause = implode(' AND ', $where);

        $total = $this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM [mail].[threads] WHERE ' . $clause, $params);
        $count = AzureSqlValueMapper::intValue($total[0]['n'] ?? 0);

        $rows = $this->connection->fetchAll(
            'SELECT ' . self::THREAD_COLUMNS . ' FROM [mail].[threads] WHERE ' . $clause
            . ' ORDER BY ' . self::ORDER_SQL[$query->sort] . ' OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY',
            $params + ['offset' => $query->offset, 'page_size' => $query->pageSize],
        );
        $items = array_map(self::summary(...), $rows);
        $next = $query->offset + count($items);

        return new ThreadPage($items, $next < $count ? ThreadQuery::encodeCursor($next) : null, $count);
    }

    public function exportRows(ExportQuery $query): array
    {
        $where = ['t.[label] IS NOT NULL', 't.[deleted_at] IS NULL'];
        $params = [];
        if ($query->decidedOnly) {
            $where[] = 't.[decision_id] IS NOT NULL';
        }
        if ($query->from !== null) {
            $where[] = 'm.[received_at] >= :from';
            $params['from'] = AzureSqlValueMapper::formatDateTime($query->from);
        }
        if ($query->to !== null) {
            $where[] = 'm.[received_at] <= :to';
            $params['to'] = AzureSqlValueMapper::formatDateTime($query->to);
        }
        $rows = $this->connection->fetchAll(
            'SELECT t.[id], m.[internet_message_id], m.[received_at], m.[from_address],
                    a.[label], a.[confidence], a.[model], a.[version], a.[skipped], a.[injection_suspected],
                    d.[label] AS [decided_label], d.[kind] AS [decision_kind], d.[decided_at], d.[decided_by_name]
             FROM [mail].[threads] t
             JOIN [mail].[assessments] a ON a.[id] = t.[assessment_id]
             JOIN [mail].[messages] m ON m.[id] = a.[message_id]
             LEFT JOIN [mail].[label_decisions] d ON d.[id] = t.[decision_id]
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY m.[received_at], t.[id]',
            $params,
        );

        return array_map(static fn (array $r): ExportRow => new ExportRow(
            AzureSqlValueMapper::stringValue($r['id']),
            self::text($r['internet_message_id']),
            AzureSqlValueMapper::requireDateTime($r['received_at'], 'received_at'),
            ExportRow::domainOf(self::text($r['from_address'])),
            AzureSqlValueMapper::stringValue($r['label']),
            self::number($r['confidence']),
            self::text($r['model']),
            self::text($r['version']),
            AzureSqlValueMapper::boolValue($r['skipped']),
            AzureSqlValueMapper::boolValue($r['injection_suspected']),
            AzureSqlValueMapper::nullableString($r['decided_label']),
            AzureSqlValueMapper::nullableString($r['decision_kind']),
            AzureSqlValueMapper::nullableDateTime($r['decided_at']),
            AzureSqlValueMapper::nullableString($r['decided_by_name']),
        ), $rows);
    }

    public function thread(string $threadId): ?ThreadRecord
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::THREAD_COLUMNS . ', [assessment_id] FROM [mail].[threads] WHERE [id] = :id AND [label] IS NOT NULL AND [deleted_at] IS NULL',
            ['id' => $threadId],
        );
        if ($rows === []) {
            return null;
        }
        $facts = $this->facts($threadId);
        $assessmentId = AzureSqlValueMapper::stringValue($rows[0]['assessment_id']);
        $assessment = null;
        foreach ($facts['assessments'] as $a) {
            if ($a->id === $assessmentId) {
                $assessment = $a;
            }
        }
        if ($assessment === null) {
            return null;
        }
        $decisionId = AzureSqlValueMapper::nullableString($rows[0]['decision_id']);
        $decision = null;
        foreach ($facts['decisions'] as $d) {
            if ($d->id === $decisionId) {
                $decision = $d;
            }
        }

        return new ThreadRecord(self::summary($rows[0]), $facts['messages'], $assessment, $decision, $this->activity($threadId), $this->links($threadId));
    }

    public function messageRef(string $messageId): ?MessageRef
    {
        $rows = $this->connection->fetchAll(
            'SELECT m.[graph_message_id], b.[address] FROM [mail].[messages] m
             JOIN [mail].[mailboxes] b ON b.[id] = m.[mailbox_id]
             JOIN [mail].[threads] t ON t.[id] = m.[thread_id] AND t.[deleted_at] IS NULL
             WHERE m.[id] = :id',
            ['id' => $messageId],
        );

        return $rows === [] ? null : new MessageRef($messageId, AzureSqlValueMapper::stringValue($rows[0]['address']), AzureSqlValueMapper::stringValue($rows[0]['graph_message_id']));
    }

    public function attachmentRef(string $attachmentId): ?AttachmentRef
    {
        $rows = $this->connection->fetchAll(
            'SELECT a.[graph_attachment_id], a.[file_name], a.[content_type], a.[size_bytes], m.[graph_message_id], b.[address]
             FROM [mail].[attachments] a
             JOIN [mail].[messages] m ON m.[id] = a.[message_id]
             JOIN [mail].[mailboxes] b ON b.[id] = m.[mailbox_id]
             JOIN [mail].[threads] t ON t.[id] = m.[thread_id] AND t.[deleted_at] IS NULL
             WHERE a.[id] = :id',
            ['id' => $attachmentId],
        );
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];

        return new AttachmentRef(
            $attachmentId,
            AzureSqlValueMapper::stringValue($r['address']),
            AzureSqlValueMapper::stringValue($r['graph_message_id']),
            AzureSqlValueMapper::stringValue($r['graph_attachment_id']),
            self::text($r['file_name']),
            self::text($r['content_type']),
            AzureSqlValueMapper::intValue($r['size_bytes']),
        );
    }

    public function markRead(string $threadId, bool $read): void
    {
        $this->runInTransaction($this->connection, function () use ($threadId, $read): void {
            $this->connection->execute('UPDATE [mail].[messages] SET [is_read] = :read WHERE [thread_id] = :thread', ['read' => $read ? 1 : 0, 'thread' => $threadId]);
            $this->refresh($threadId);
        });
    }

    public function recordDecision(string $threadId, NewDecision $decision): void
    {
        $this->runInTransaction($this->connection, function () use ($threadId, $decision): void {
            $assessed = $this->connection->fetchAll('SELECT [label] FROM [mail].[assessments] WHERE [id] = :id', ['id' => $decision->assessmentId]);
            $before = AzureSqlValueMapper::stringValue($assessed[0]['label'] ?? $decision->label);
            $this->connection->execute(
                'INSERT INTO [mail].[label_decisions] ([id], [thread_id], [assessment_id], [kind], [label], [decided_by_user_id], [decided_by_name], [decided_by_email], [note], [decided_at])
                 VALUES (:id, :thread, :assessment, :kind, :label, :user, :name, :email, :note, :at)',
                [
                    'id' => MailIds::new(),
                    'thread' => $threadId,
                    'assessment' => $decision->assessmentId,
                    'kind' => $decision->kind,
                    'label' => $decision->label,
                    'user' => $decision->userId,
                    'name' => mb_substr($decision->decidedBy->name, 0, 200),
                    'email' => mb_substr($decision->decidedBy->address, 0, 320),
                    'note' => $decision->note,
                    'at' => AzureSqlValueMapper::formatDateTime($decision->decidedAt),
                ],
            );
            $note = $decision->note !== null ? ' ' . $decision->note : '';
            $this->activityRow(
                $threadId,
                null,
                $decision->kind === 'confirm' ? V::ACTIVITY_LABEL_CONFIRMED : V::ACTIVITY_LABEL_CORRECTED,
                $decision->kind === 'confirm'
                    ? sprintf('Confirmed the label: %s.%s', V::labelWord($before), $note)
                    : sprintf('Corrected the label from %s to %s.%s', V::labelWord($before), V::labelWord($decision->label), $note),
                $decision->decidedAt,
                $decision->userId,
                $decision->decidedBy,
            );
            $this->refresh($threadId);
        });
    }

    public function recordDraft(string $threadId, NewLink $link, MailParty $actor): void
    {
        $this->runInTransaction($this->connection, function () use ($threadId, $link, $actor): void {
            $this->requireOpen($threadId);
            $this->connection->execute(
                'INSERT INTO [mail].[message_links] ([id], [thread_id], [message_id], [kind], [rfq_public_id], [reference], [title], [created_by_user_id], [created_at])
                 VALUES (:id, :thread, :message, N\'rfq_draft\', :public, :reference, :title, :user, :at)',
                [
                    'id' => MailIds::new(),
                    'thread' => $threadId,
                    'message' => $link->messageId,
                    'public' => $link->publicId,
                    'reference' => mb_substr($link->reference, 0, 100),
                    'title' => mb_substr($link->title, 0, 500),
                    'user' => $link->createdByUserId,
                    'at' => AzureSqlValueMapper::formatDateTime($link->createdAt),
                ],
            );
            $this->connection->execute('UPDATE [mail].[threads] SET [review_state] = :state WHERE [id] = :id', ['state' => V::STATE_DRAFTED, 'id' => $threadId]);
            $this->activityRow($threadId, null, V::ACTIVITY_DRAFT_CREATED, sprintf('Created RFQ draft %s.', $link->reference), $link->createdAt, $link->createdByUserId, $actor);
            $this->refresh($threadId);
        });
    }

    public function recordFollowup(string $threadId, string $messageId, NewLink $draft, MailParty $actor): bool
    {
        return $this->runInTransaction($this->connection, function () use ($threadId, $messageId, $draft, $actor): bool {
            // The thread row is the lock: two passes linking the same message take turns, and the
            // second finds the first's link. (UX_mail_message_links_followup backs this up.)
            $this->connection->fetchAll('SELECT [id] FROM [mail].[threads] WITH (UPDLOCK, HOLDLOCK) WHERE [id] = :id', ['id' => $threadId]);
            $found = $this->connection->fetchAll(
                'SELECT 1 AS [one] FROM [mail].[message_links] WHERE [message_id] = :message AND [kind] = N\'rfq_followup\'',
                ['message' => $messageId],
            );
            if ($found !== []) {
                return false;
            }
            $this->connection->execute(
                'INSERT INTO [mail].[message_links] ([id], [thread_id], [message_id], [kind], [rfq_public_id], [reference], [title], [created_by_user_id], [created_at])
                 VALUES (:id, :thread, :message, N\'rfq_followup\', :public, :reference, :title, NULL, :at)',
                [
                    'id' => MailIds::new(),
                    'thread' => $threadId,
                    'message' => $messageId,
                    'public' => $draft->publicId,
                    'reference' => mb_substr($draft->reference, 0, 100),
                    'title' => mb_substr($draft->title, 0, 500),
                    'at' => AzureSqlValueMapper::formatDateTime($draft->createdAt),
                ],
            );
            $this->activityRow($threadId, $messageId, V::ACTIVITY_FOLLOWUP_LINKED, sprintf('Linked this message to RFQ draft %s.', $draft->reference), $draft->createdAt, null, $actor);
            $this->refresh($threadId);

            return true;
        });
    }

    public function recordIgnore(string $threadId, MailParty $actor, int $userId, ?string $note, DateTimeImmutable $at): void
    {
        $this->runInTransaction($this->connection, function () use ($threadId, $actor, $userId, $note, $at): void {
            $this->requireOpen($threadId);
            $this->connection->execute('UPDATE [mail].[threads] SET [review_state] = :state WHERE [id] = :id', ['state' => V::STATE_IGNORED, 'id' => $threadId]);
            $this->activityRow($threadId, null, V::ACTIVITY_IGNORED, $note !== null ? 'Ignored: ' . $note : 'Ignored.', $at, $userId, $actor);
            $this->refresh($threadId);
        });
    }

    public function deleteThread(string $threadId, MailParty $actor, int $userId, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE [mail].[threads] SET [deleted_at] = :at, [deleted_by_user_id] = :user, [deleted_by_name] = :name, [updated_at] = SYSUTCDATETIME()
             WHERE [id] = :id AND [deleted_at] IS NULL',
            ['at' => AzureSqlValueMapper::formatDateTime($at), 'user' => $userId, 'name' => mb_substr($actor->name, 0, 200), 'id' => $threadId],
        );
    }

    public function ensureMailbox(string $address, string $displayName): string
    {
        $find = fn (): ?string => AzureSqlValueMapper::nullableString(
            $this->connection->fetchAll('SELECT [id] FROM [mail].[mailboxes] WHERE [address] = :address', ['address' => $address])[0]['id'] ?? null,
        );
        $existing = $find();
        if ($existing !== null) {
            return $existing;
        }
        try {
            $id = MailIds::new();
            $this->connection->execute(
                'INSERT INTO [mail].[mailboxes] ([id], [address], [display_name]) VALUES (:id, :address, :name)',
                ['id' => $id, 'address' => $address, 'name' => mb_substr($displayName, 0, 200)],
            );

            return $id;
        } catch (DatabaseUnavailable $failure) {
            // Two pollers met on the unique address: the other one won, so use its row.
            $winner = in_array($failure->sqlDriverCode(), self::DUPLICATE_KEY_CODES, true) ? $find() : null;
            if ($winner === null) {
                throw $failure;
            }

            return $winner;
        }
    }

    public function recordSync(string $mailboxId, string $connection, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE [mail].[mailboxes] SET [connection] = :connection, [last_sync_at] = :at WHERE [id] = :id',
            ['connection' => $connection, 'at' => AzureSqlValueMapper::formatDateTime($at), 'id' => $mailboxId],
        );
    }

    public function findMessageId(string $mailboxId, string $internetMessageId): ?string
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id] FROM [mail].[messages] WHERE [mailbox_id] = :mailbox AND [internet_message_id] = :internet',
            ['mailbox' => $mailboxId, 'internet' => $internetMessageId],
        );

        return $rows === [] ? null : AzureSqlValueMapper::stringValue($rows[0]['id']);
    }

    public function upsertMessage(string $mailboxId, NewMessage $message): RecordedMessage
    {
        $existing = $this->recorded($mailboxId, $message->internetMessageId);
        if ($existing !== null) {
            return $existing;
        }
        try {
            return $this->runInTransaction($this->connection, fn (): RecordedMessage => $this->insertMessage($mailboxId, $message));
        } catch (DatabaseUnavailable $failure) {
            // Two pollers met on the unique internetMessageId (or the conversation): the other one wins.
            $winner = in_array($failure->sqlDriverCode(), self::DUPLICATE_KEY_CODES, true) ? $this->recorded($mailboxId, $message->internetMessageId) : null;
            if ($winner === null) {
                throw $failure;
            }

            return $winner;
        }
    }

    public function appendAssessment(string $messageId, NewAssessment $assessment): void
    {
        $this->runInTransaction($this->connection, function () use ($messageId, $assessment): void {
            $thread = $this->connection->fetchAll('SELECT [thread_id] FROM [mail].[messages] WHERE [id] = :id', ['id' => $messageId]);
            $threadId = AzureSqlValueMapper::stringValue($thread[0]['thread_id'] ?? '');
            if ($threadId === '') {
                throw new \RuntimeException('Cannot assess a message that is not recorded.');
            }
            $this->connection->execute(
                'INSERT INTO [mail].[assessments] ([id], [message_id], [label], [confidence], [reason], [model], [latency_ms], [version], [skipped], [skip_reason], [injection_suspected], [assessed_at])
                 VALUES (:id, :message, :label, :confidence, :reason, :model, :latency, :version, :skipped, :skip, :injection, :at)',
                [
                    'id' => MailIds::new(),
                    'message' => $messageId,
                    'label' => $assessment->label,
                    'confidence' => number_format($assessment->confidence, 4, '.', ''),
                    'reason' => $assessment->reason,
                    'model' => mb_substr($assessment->model, 0, 100),
                    'latency' => $assessment->latencyMs,
                    'version' => mb_substr($assessment->version, 0, 100),
                    'skipped' => $assessment->skipped ? 1 : 0,
                    'skip' => $assessment->skipReason,
                    'injection' => $assessment->injectionSuspected ? 1 : 0,
                    'at' => AzureSqlValueMapper::formatDateTime($assessment->assessedAt),
                ],
            );
            $this->activityRow(
                $threadId,
                $messageId,
                $assessment->skipped ? V::ACTIVITY_SKIPPED : V::ACTIVITY_CLASSIFIED,
                $assessment->skipped ? $assessment->reason : sprintf('Classified as %s (%d%%).', V::labelWord($assessment->label), (int) round($assessment->confidence * 100)),
                $assessment->assessedAt,
                null,
                null,
            );
            $this->refresh($threadId);
        });
    }

    // --- internals ---

    private function recorded(string $mailboxId, string $internetMessageId): ?RecordedMessage
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [thread_id] FROM [mail].[messages] WHERE [mailbox_id] = :mailbox AND [internet_message_id] = :internet',
            ['mailbox' => $mailboxId, 'internet' => $internetMessageId],
        );

        return $rows === [] ? null : new RecordedMessage(AzureSqlValueMapper::stringValue($rows[0]['id']), AzureSqlValueMapper::stringValue($rows[0]['thread_id']), false);
    }

    private function insertMessage(string $mailboxId, NewMessage $message): RecordedMessage
    {
        $conversation = $message->conversationId !== '' ? $message->conversationId : 'internet:' . $message->internetMessageId;
        $found = $this->connection->fetchAll(
            'SELECT [id] FROM [mail].[threads] WITH (UPDLOCK, HOLDLOCK) WHERE [mailbox_id] = :mailbox AND [conversation_id] = :conversation',
            ['mailbox' => $mailboxId, 'conversation' => mb_substr($conversation, 0, 450)],
        );
        if ($found === []) {
            $threadId = MailIds::new();
            $this->connection->execute(
                'INSERT INTO [mail].[threads] ([id], [mailbox_id], [conversation_id], [subject], [latest_at], [message_count], [unread_count], [from_name], [from_address], [preview])
                 VALUES (:id, :mailbox, :conversation, :subject, :latest, 0, 0, :from_name, :from_address, :preview)',
                [
                    'id' => $threadId,
                    'mailbox' => $mailboxId,
                    'conversation' => mb_substr($conversation, 0, 450),
                    'subject' => $message->subject,
                    'latest' => AzureSqlValueMapper::formatDateTime($message->receivedAt),
                    'from_name' => $message->from->name,
                    'from_address' => $message->from->address,
                    'preview' => $message->bodyPreview,
                ],
            );
        } else {
            $threadId = AzureSqlValueMapper::stringValue($found[0]['id']);
            // A new message in a deleted conversation brings it back (a redelivered one never reaches here).
            $this->connection->execute(
                'UPDATE [mail].[threads] SET [deleted_at] = NULL, [deleted_by_user_id] = NULL, [deleted_by_name] = NULL WHERE [id] = :id AND [deleted_at] IS NOT NULL',
                ['id' => $threadId],
            );
        }

        $messageId = MailIds::new();
        $this->connection->execute(
            'INSERT INTO [mail].[messages] ([id], [mailbox_id], [thread_id], [graph_message_id], [internet_message_id], [subject], [from_name], [from_address], [to_json], [cc_json], [received_at], [body_preview], [is_read])
             VALUES (:id, :mailbox, :thread, :graph, :internet, :subject, :from_name, :from_address, :to_json, :cc_json, :received, :preview, :is_read)',
            [
                'id' => $messageId,
                'mailbox' => $mailboxId,
                'thread' => $threadId,
                'graph' => mb_substr($message->graphMessageId, 0, 450),
                'internet' => mb_substr($message->internetMessageId, 0, 450),
                'subject' => $message->subject,
                'from_name' => $message->from->name,
                'from_address' => $message->from->address,
                'to_json' => self::partiesJson($message->to),
                'cc_json' => self::partiesJson($message->cc),
                'received' => AzureSqlValueMapper::formatDateTime($message->receivedAt),
                'preview' => $message->bodyPreview,
                'is_read' => $message->isRead ? 1 : 0,
            ],
        );
        foreach ($message->attachments as $a) {
            $this->connection->execute(
                'INSERT INTO [mail].[attachments] ([id], [message_id], [graph_attachment_id], [file_name], [content_type], [size_bytes], [is_inline])
                 VALUES (:id, :message, :graph, :name, :type, :size, :inline)',
                [
                    'id' => MailIds::new(),
                    'message' => $messageId,
                    'graph' => mb_substr($a->graphAttachmentId, 0, 450),
                    'name' => $a->fileName,
                    'type' => $a->mimeType,
                    'size' => $a->sizeBytes,
                    'inline' => $a->isInline ? 1 : 0,
                ],
            );
        }
        $this->activityRow(
            $threadId,
            $messageId,
            V::ACTIVITY_RECEIVED,
            sprintf('Message received from %s.', $message->from->name !== '' ? $message->from->name : $message->from->address),
            $message->receivedAt,
            null,
            null,
        );
        $this->refresh($threadId);

        return new RecordedMessage($messageId, $threadId, true);
    }

    private function requireOpen(string $threadId): void
    {
        $rows = $this->connection->fetchAll('SELECT [review_state] FROM [mail].[threads] WITH (UPDLOCK, HOLDLOCK) WHERE [id] = :id', ['id' => $threadId]);
        if (($rows[0]['review_state'] ?? null) !== V::STATE_OPEN) {
            // Someone else actioned it between the guard and this write.
            throw new MailActionBlocked([new MailBlocker(V::BLOCKER_ALREADY_ACTIONED, 'This conversation has already been actioned.')]);
        }
    }

    private function activityRow(string $threadId, ?string $messageId, string $kind, string $description, DateTimeImmutable $at, ?int $userId, ?MailParty $actor): void
    {
        $this->connection->execute(
            'INSERT INTO [mail].[activity] ([id], [thread_id], [message_id], [kind], [actor_user_id], [actor_name], [actor_email], [description], [created_at])
             VALUES (:id, :thread, :message, :kind, :user, :name, :email, :description, :at)',
            [
                'id' => MailIds::new(),
                'thread' => $threadId,
                'message' => $messageId,
                'kind' => $kind,
                'user' => $userId,
                'name' => $actor === null ? null : mb_substr($actor->name, 0, 200),
                'email' => $actor === null ? null : mb_substr($actor->address, 0, 320),
                'description' => mb_substr($description, 0, 1000),
                'at' => AzureSqlValueMapper::formatDateTime($at),
            ],
        );
    }

    /** Rewrites the thread's summary columns from its rows. Always the last step of a write. */
    private function refresh(string $threadId): void
    {
        $thread = $this->connection->fetchAll(
            'SELECT t.[mailbox_id], t.[subject], t.[review_state], b.[address] FROM [mail].[threads] t JOIN [mail].[mailboxes] b ON b.[id] = t.[mailbox_id] WHERE t.[id] = :id',
            ['id' => $threadId],
        );
        $facts = $this->facts($threadId);
        $projection = ThreadProjector::project(
            $threadId,
            AzureSqlValueMapper::stringValue($thread[0]['mailbox_id']),
            AzureSqlValueMapper::stringValue($thread[0]['address']),
            self::text($thread[0]['subject']),
            AzureSqlValueMapper::stringValue($thread[0]['review_state']),
            $facts['messages'],
            $facts['assessments'],
            $facts['decisions'],
        );
        $s = $projection->summary;
        $injection = false;
        foreach ($facts['assessments'] as $a) {
            if ($a->id === $projection->assessmentId) {
                $injection = $a->injectionSuspected;
            }
        }
        $this->connection->execute(
            'UPDATE [mail].[threads] SET
                [latest_at] = :latest, [message_count] = :count, [unread_count] = :unread, [has_attachments] = :attachments,
                [from_name] = :from_name, [from_address] = :from_address, [preview] = :preview,
                [assessment_id] = :assessment, [label] = :label, [confidence] = :confidence, [skipped] = :skipped,
                [injection_suspected] = :injection, [decision_id] = :decision, [effective_label] = :effective,
                [updated_at] = SYSUTCDATETIME()
             WHERE [id] = :id',
            [
                'latest' => AzureSqlValueMapper::formatDateTime($s->latestAt),
                'count' => $s->messageCount,
                'unread' => $projection->unreadCount,
                'attachments' => $s->hasAttachments ? 1 : 0,
                'from_name' => $s->from->name,
                'from_address' => $s->from->address,
                'preview' => $s->preview,
                'assessment' => $projection->assessmentId,
                'label' => $projection->classified ? $s->label : null,
                'confidence' => $projection->classified ? number_format($s->confidence, 4, '.', '') : null,
                'skipped' => $s->skipped ? 1 : 0,
                'injection' => $injection ? 1 : 0,
                'decision' => $projection->decisionId,
                'effective' => $projection->classified ? $s->effectiveLabel : null,
                'id' => $threadId,
            ],
        );
    }

    /** @return array{messages: list<MessageView>, assessments: list<AssessmentView>, decisions: list<DecisionView>} */
    private function facts(string $threadId): array
    {
        $files = [];
        $attachments = $this->connection->fetchAll(
            'SELECT a.[id], a.[message_id], a.[file_name], a.[content_type], a.[size_bytes]
             FROM [mail].[attachments] a JOIN [mail].[messages] m ON m.[id] = a.[message_id]
             WHERE m.[thread_id] = :thread AND a.[is_inline] = 0 ORDER BY a.[file_name], a.[id]',
            ['thread' => $threadId],
        );
        foreach ($attachments as $a) {
            $messageId = AzureSqlValueMapper::stringValue($a['message_id']);
            $files[$messageId][] = new AttachmentView(AzureSqlValueMapper::stringValue($a['id']), $messageId, self::text($a['file_name']), self::text($a['content_type']), AzureSqlValueMapper::intValue($a['size_bytes']));
        }
        $messages = array_map(static fn (array $r): MessageView => new MessageView(
            AzureSqlValueMapper::stringValue($r['id']),
            self::text($r['internet_message_id']),
            new MailParty(self::text($r['from_name']), self::text($r['from_address'])),
            self::parties($r['to_json']),
            self::parties($r['cc_json']),
            AzureSqlValueMapper::requireDateTime($r['received_at'], 'received_at'),
            self::text($r['body_preview']),
            $files[AzureSqlValueMapper::stringValue($r['id'])] ?? [],
            AzureSqlValueMapper::boolValue($r['is_read']),
        ), $this->connection->fetchAll(
            'SELECT [id], [internet_message_id], [from_name], [from_address], [to_json], [cc_json], [received_at], [body_preview], [is_read]
             FROM [mail].[messages] WHERE [thread_id] = :thread ORDER BY [received_at], [id]',
            ['thread' => $threadId],
        ));
        $assessments = array_map(static fn (array $r): AssessmentView => new AssessmentView(
            AzureSqlValueMapper::stringValue($r['id']),
            AzureSqlValueMapper::stringValue($r['message_id']),
            AzureSqlValueMapper::stringValue($r['label']),
            self::number($r['confidence']),
            self::text($r['reason']),
            self::text($r['model']),
            AzureSqlValueMapper::nullableInt($r['latency_ms']),
            self::text($r['version']),
            AzureSqlValueMapper::requireDateTime($r['assessed_at'], 'assessed_at'),
            AzureSqlValueMapper::boolValue($r['skipped']),
            AzureSqlValueMapper::boolValue($r['injection_suspected']),
        ), $this->connection->fetchAll(
            'SELECT a.[id], a.[message_id], a.[label], a.[confidence], a.[reason], a.[model], a.[latency_ms], a.[version], a.[skipped], a.[injection_suspected], a.[assessed_at]
             FROM [mail].[assessments] a JOIN [mail].[messages] m ON m.[id] = a.[message_id]
             WHERE m.[thread_id] = :thread ORDER BY a.[assessed_at], a.[id]',
            ['thread' => $threadId],
        ));
        $decisions = array_map(static fn (array $r): DecisionView => new DecisionView(
            AzureSqlValueMapper::stringValue($r['id']),
            AzureSqlValueMapper::stringValue($r['assessment_id']),
            AzureSqlValueMapper::stringValue($r['kind']),
            AzureSqlValueMapper::stringValue($r['label']),
            new MailParty(self::text($r['decided_by_name']), self::text($r['decided_by_email'])),
            AzureSqlValueMapper::requireDateTime($r['decided_at'], 'decided_at'),
            AzureSqlValueMapper::nullableString($r['note']),
        ), $this->connection->fetchAll(
            'SELECT [id], [assessment_id], [kind], [label], [decided_by_name], [decided_by_email], [note], [decided_at]
             FROM [mail].[label_decisions] WHERE [thread_id] = :thread ORDER BY [decided_at], [id]',
            ['thread' => $threadId],
        ));

        return ['messages' => $messages, 'assessments' => $assessments, 'decisions' => $decisions];
    }

    /** @return list<ActivityView> */
    private function activity(string $threadId): array
    {
        return array_map(static function (array $r): ActivityView {
            $name = AzureSqlValueMapper::nullableString($r['actor_name']);

            return new ActivityView(
                AzureSqlValueMapper::stringValue($r['id']),
                AzureSqlValueMapper::requireDateTime($r['created_at'], 'created_at'),
                $name === null ? null : new MailParty($name, self::text($r['actor_email'] ?? '')),
                AzureSqlValueMapper::stringValue($r['kind']),
                self::text($r['description']),
            );
        }, $this->connection->fetchAll(
            'SELECT [id], [kind], [actor_name], [actor_email], [description], [created_at] FROM [mail].[activity] WHERE [thread_id] = :thread ORDER BY [created_at], [id]',
            ['thread' => $threadId],
        ));
    }

    /** @return list<LinkView> */
    private function links(string $threadId): array
    {
        return array_map(static fn (array $r): LinkView => new LinkView(
            AzureSqlValueMapper::stringValue($r['rfq_public_id']),
            self::text($r['reference']),
            self::text($r['title']),
            AzureSqlValueMapper::requireDateTime($r['created_at'], 'created_at'),
            AzureSqlValueMapper::stringValue($r['kind']),
            AzureSqlValueMapper::nullableString($r['message_id'] ?? null),
        ), $this->connection->fetchAll(
            'SELECT [rfq_public_id], [reference], [title], [created_at], [kind], [message_id] FROM [mail].[message_links] WHERE [thread_id] = :thread ORDER BY [created_at], [id]',
            ['thread' => $threadId],
        ));
    }

    /** @param array<string, mixed> $r */
    private static function summary(array $r): ThreadSummary
    {
        return new ThreadSummary(
            AzureSqlValueMapper::stringValue($r['id']),
            AzureSqlValueMapper::stringValue($r['mailbox_id']),
            self::text($r['subject']),
            new MailParty(self::text($r['from_name']), self::text($r['from_address'])),
            self::text($r['preview']),
            AzureSqlValueMapper::requireDateTime($r['latest_at'], 'latest_at'),
            AzureSqlValueMapper::intValue($r['message_count']),
            AzureSqlValueMapper::boolValue($r['has_attachments']),
            AzureSqlValueMapper::intValue($r['unread_count']) > 0,
            AzureSqlValueMapper::stringValue($r['label']),
            self::number($r['confidence']),
            AzureSqlValueMapper::stringValue($r['effective_label']),
            AzureSqlValueMapper::nullableString($r['decision_id']) !== null,
            AzureSqlValueMapper::boolValue($r['skipped']),
            AzureSqlValueMapper::stringValue($r['review_state']),
        );
    }

    /** A text column as stored: nothing is trimmed (a subject keeps its spacing). */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** A DECIMAL column, which the driver hands back as text. */
    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** @param list<MailParty> $parties */
    private static function partiesJson(array $parties): string
    {
        return json_encode(array_map(static fn (MailParty $p): array => $p->toArray(), $parties), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<MailParty> */
    private static function parties(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $p) {
            if (is_array($p)) {
                $out[] = new MailParty(self::text($p['name'] ?? ''), self::text($p['address'] ?? ''));
            }
        }

        return $out;
    }
}
