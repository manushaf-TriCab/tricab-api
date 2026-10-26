<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailDrafts;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailAttachment;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailDraft;
use TriCab\Core\Finance\Ap2\Domain\Ap2SyncRun;

/**
 * `finance.ap2_mail`, `finance.ap2_mail_attachments` and `finance.ap2_sync_runs`
 * (`create-finance-ap2-schema.sql`, plus `alter-finance-ap2-mail-round3.sql` for `draft_attachment_ids`, `alter-finance-ap2-ai-usage.sql` for `extraction_json`).
 */
final class AzureSqlAp2MailStore extends AzureSqlAp2Support implements Ap2MailStore, Ap2MailDrafts
{
    private const MAIL = '[finance].[ap2_mail]';

    private const FILES = '[finance].[ap2_mail_attachments]';

    private const COLUMNS = [
        'id', 'supplier_id', 'invoice_id', 'mailbox', 'graph_message_id', 'received_at', 'subject', 'body_preview', 'body', 'category',
        'owner_user_id', 'owner_name', 'owner_email', 'status', 'is_read', 'due', 'from_address', 'cc', 'reply_to', 'record_history',
        'draft', 'draft_reviewed', 'draft_attachment_ids', 'sent', 'sent_at', 'approval_id', 'ai_summary', 'ai_confidence', 'extraction_json', 'created_at', 'updated_at',
    ];

    public function all(?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array
    {
        $where = [];
        $params = [];
        if ($from !== null) {
            $where[] = '[received_at] >= :from_at';
            $params['from_at'] = self::at($from);
        }
        if ($until !== null) {
            $where[] = '[received_at] < :until_at';
            $params['until_at'] = self::at($until);
        }
        $sql = 'SELECT ' . self::select() . ' FROM ' . self::MAIL . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY [received_at] DESC, [id] DESC';

        return array_map(self::mail(...), $this->connection->fetchAll($sql, $params));
    }

    public function findMail(string $id): ?Ap2Mail
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::select() . ' FROM ' . self::MAIL . ' WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::mail($rows[0]);
    }

    public function hasGraphMessage(string $mailbox, string $graphMessageId): bool
    {
        return $this->connection->fetchAll(
            'SELECT TOP (1) 1 AS [x] FROM ' . self::MAIL . ' WHERE [mailbox] = :mailbox AND [graph_message_id] = :message',
            ['mailbox' => $mailbox, 'message' => $graphMessageId],
        ) !== [];
    }

    public function insertMail(Ap2Mail $m, array $attachments): void
    {
        $names = array_map(static fn (string $c): string => '[' . $c . ']', self::COLUMNS);
        $marks = array_map(static fn (string $c): string => ':' . $c, self::COLUMNS);
        $this->transactional(function () use ($m, $attachments, $names, $marks): null {
            $this->write('INSERT INTO ' . self::MAIL . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $marks) . ')', self::params($m), 'The email is already stored');
            foreach ($attachments as $a) {
                $this->connection->execute(
                    'INSERT INTO ' . self::FILES . ' ([id], [mail_id], [file_id], [graph_attachment_id], [name], [size], [content_type], [created_at])
                     VALUES (:id, :mail_id, :file_id, :graph_id, :name, :size, :content_type, :created_at)',
                    [
                        'id' => $a->id,
                        'mail_id' => $a->mailId,
                        'file_id' => $a->fileId,
                        'graph_id' => $a->graphAttachmentId,
                        'name' => mb_substr($a->name, 0, 255),
                        'size' => $a->size,
                        'content_type' => mb_substr($a->contentType, 0, 150),
                        'created_at' => self::at($a->createdAt),
                    ],
                );
            }

            return null;
        });
    }

    public function updateMail(Ap2Mail $m): void
    {
        $sets = [];
        foreach (self::COLUMNS as $column) {
            if ($column !== 'id' && $column !== 'created_at') {
                $sets[] = '[' . $column . '] = :' . $column;
            }
        }
        $params = self::params($m);
        unset($params['created_at']);
        $this->connection->execute('UPDATE ' . self::MAIL . ' SET ' . implode(', ', $sets) . ' WHERE [id] = :id', $params);
    }

    public function attachmentsOf(string $mailId): array
    {
        return array_map(
            self::file(...),
            $this->connection->fetchAll('SELECT ' . self::fileColumns() . ' FROM ' . self::FILES . ' WHERE [mail_id] = :id ORDER BY [created_at], [id]', ['id' => $mailId]),
        );
    }

    public function attachmentsOfMany(array $mailIds): array
    {
        $out = [];
        foreach ($mailIds as $id) {
            $out[$id] = [];
        }
        // One query per hundred ids keeps the parameter count far under the driver's limit.
        foreach (array_chunk($mailIds, 100) as $chunk) {
            $params = [];
            $marks = [];
            foreach ($chunk as $i => $id) {
                $params['m' . $i] = $id;
                $marks[] = ':m' . $i;
            }
            foreach ($this->connection->fetchAll('SELECT ' . self::fileColumns() . ' FROM ' . self::FILES . ' WHERE [mail_id] IN (' . implode(', ', $marks) . ') ORDER BY [created_at], [id]', $params) as $row) {
                $file = self::file($row);
                $out[$file->mailId][] = $file;
            }
        }

        return $out;
    }

    public function attachment(string $mailId, string $attachmentId): ?Ap2MailAttachment
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::fileColumns() . ' FROM ' . self::FILES . ' WHERE [mail_id] = :mail AND [id] = :id',
            ['mail' => $mailId, 'id' => $attachmentId],
        );

        return $rows === [] ? null : self::file($rows[0]);
    }

    public function beginRun(Ap2SyncRun $r): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ap2_sync_runs] ([id], [kind], [status], [from_date], [to_date], [fetched], [created], [skipped], [error], [started_by_user_id], [started_at], [finished_at], [processed], [failed], [updated_at])
             VALUES (:id, :kind, :status, :from_date, :to_date, :fetched, :created, :skipped, :error, :user, :started_at, :finished_at, :processed, :failed, :updated_at)',
            self::runParams($r),
        );
    }

    public function finishRun(Ap2SyncRun $r): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ap2_sync_runs] SET [status] = :status, [fetched] = :fetched, [created] = :created, [skipped] = :skipped, [error] = :error, [finished_at] = :finished_at, [processed] = :processed, [failed] = :failed, [updated_at] = :updated_at WHERE [id] = :id',
            array_intersect_key(self::runParams($r), array_flip(['id', 'status', 'fetched', 'created', 'skipped', 'error', 'finished_at', 'processed', 'failed', 'updated_at'])),
        );
    }

    public function saveProgress(Ap2SyncRun $r): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ap2_sync_runs] SET [fetched] = :fetched, [created] = :created, [skipped] = :skipped, [error] = :error, [processed] = :processed, [failed] = :failed, [updated_at] = :updated_at WHERE [id] = :id AND [status] = N\'Running\'',
            array_intersect_key(self::runParams($r), array_flip(['id', 'fetched', 'created', 'skipped', 'error', 'processed', 'failed', 'updated_at'])),
        );
    }

    public function lastSucceededRun(string $kind): ?Ap2SyncRun
    {
        return $this->oneRun('WHERE [kind] = :kind AND [status] = N\'Succeeded\' ORDER BY [started_at] DESC', ['kind' => $kind]);
    }

    public function findRun(string $id): ?Ap2SyncRun
    {
        return $this->oneRun('WHERE [id] = :id', ['id' => $id]);
    }

    public function runningRun(string $kind): ?Ap2SyncRun
    {
        return $this->oneRun('WHERE [kind] = :kind AND [status] = N\'Running\' ORDER BY [started_at] DESC', ['kind' => $kind]);
    }

    /** @param array<string, scalar|null> $params */
    private function oneRun(string $where, array $params): ?Ap2SyncRun
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) [id], [kind], [status], [from_date], [to_date], [fetched], [created], [skipped], [error], [started_by_user_id], [started_at], [finished_at], [processed], [failed], [updated_at]
             FROM [finance].[ap2_sync_runs] ' . $where,
            $params,
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return new Ap2SyncRun(
            self::id($row['id']),
            self::text($row['kind']),
            self::text($row['status']),
            self::when($row['started_at'], 'started_at'),
            self::day($row['from_date']),
            self::day($row['to_date']),
            self::int($row['fetched']),
            self::int($row['created']),
            self::int($row['skipped']),
            self::textOrNull($row['error']),
            AzureSqlValueMapper::nullableInt($row['started_by_user_id']),
            self::whenOrNull($row['finished_at']),
            self::int($row['processed'] ?? 0),
            self::int($row['failed'] ?? 0),
            self::whenOrNull($row['updated_at'] ?? null),
        );
    }

    public function find(string $mailId): ?Ap2MailDraft
    {
        $m = $this->findMail($mailId);

        return $m === null ? null : new Ap2MailDraft($m->id, $m->supplierId, $m->subject, $m->draft, $m->replyTo, $m->cc, $m->draftAttachmentIds);
    }

    public function linkApproval(string $mailId, string $approvalId): void
    {
        $this->connection->execute('UPDATE ' . self::MAIL . ' SET [approval_id] = :approval WHERE [id] = :id', ['approval' => $approvalId, 'id' => $mailId]);
    }

    private static function select(): string
    {
        return implode(', ', array_map(static fn (string $c): string => '[' . $c . ']', self::COLUMNS));
    }

    private static function fileColumns(): string
    {
        return '[id], [mail_id], [file_id], [graph_attachment_id], [name], [size], [content_type], [created_at]';
    }

    /** @return array<string, scalar|null> */
    private static function params(Ap2Mail $m): array
    {
        return [
            'id' => $m->id,
            'supplier_id' => $m->supplierId,
            'invoice_id' => $m->invoiceId,
            'mailbox' => mb_substr($m->mailbox, 0, 320),
            'graph_message_id' => $m->graphMessageId,
            'received_at' => self::at($m->receivedAt),
            'subject' => mb_substr($m->subject, 0, 400),
            'body_preview' => mb_substr($m->bodyPreview, 0, 1000),
            'body' => $m->body,
            'category' => $m->category,
            'owner_user_id' => $m->owner?->userId,
            'owner_name' => $m->owner->name ?? '',
            'owner_email' => $m->owner->email ?? '',
            'status' => $m->status,
            'is_read' => $m->read ? 1 : 0,
            'due' => $m->due,
            'from_address' => mb_substr($m->from, 0, 320),
            'cc' => mb_substr($m->cc, 0, 1000),
            'reply_to' => mb_substr($m->replyTo, 0, 320),
            'record_history' => $m->recordHistory ? 1 : 0,
            'draft' => $m->draft,
            'draft_reviewed' => $m->draftReviewed ? 1 : 0,
            'draft_attachment_ids' => json_encode($m->draftAttachmentIds, JSON_THROW_ON_ERROR),
            'sent' => $m->sent ? 1 : 0,
            'sent_at' => self::atOrNull($m->sentAt),
            'approval_id' => $m->approvalId,
            'ai_summary' => $m->aiSummary,
            'ai_confidence' => $m->aiConfidence === null ? null : number_format($m->aiConfidence, 8, '.', ''),
            'extraction_json' => $m->extraction?->toJson(),
            'created_at' => self::at($m->createdAt),
            'updated_at' => self::at($m->updatedAt),
        ];
    }

    /** @return array<string, scalar|null> */
    private static function runParams(Ap2SyncRun $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'status' => $r->status,
            'from_date' => $r->fromDate,
            'to_date' => $r->toDate,
            'fetched' => $r->fetched,
            'created' => $r->created,
            'skipped' => $r->skipped,
            'error' => $r->error === null ? null : mb_substr($r->error, 0, 4000),
            'user' => $r->startedByUserId,
            'started_at' => self::at($r->startedAt),
            'finished_at' => self::atOrNull($r->finishedAt),
            'processed' => $r->processed,
            'failed' => $r->failed,
            'updated_at' => self::atOrNull($r->updatedAt),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function mail(array $row): Ap2Mail
    {
        $ids = [];
        $decoded = json_decode(self::text($row['draft_attachment_ids'] ?? '[]'), true);
        foreach (is_array($decoded) ? $decoded : [] as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }
        $confidence = $row['ai_confidence'] ?? null;

        return new Ap2Mail(
            self::id($row['id']),
            self::when($row['received_at'], 'received_at'),
            self::when($row['created_at'], 'created_at'),
            self::when($row['updated_at'], 'updated_at'),
            self::textOrNull($row['supplier_id']),
            self::textOrNull($row['invoice_id']),
            self::text($row['mailbox']),
            self::textOrNull($row['graph_message_id']),
            self::text($row['subject']),
            self::text($row['body_preview']),
            self::text($row['body']),
            self::text($row['category']),
            self::person($row, 'owner'),
            self::text($row['status']),
            self::bool($row['is_read']),
            self::day($row['due']),
            self::text($row['from_address']),
            self::text($row['cc']),
            self::text($row['reply_to']),
            self::bool($row['record_history']),
            self::text($row['draft']),
            self::bool($row['draft_reviewed']),
            $ids,
            self::bool($row['sent']),
            self::whenOrNull($row['sent_at']),
            self::textOrNull($row['approval_id']),
            self::textOrNull($row['ai_summary']),
            is_scalar($confidence) ? (float) $confidence : null,
            \TriCab\Core\Finance\Ap2\Domain\Ap2MailExtraction::fromJson(self::textOrNull($row['extraction_json'] ?? null)),
        );
    }

    /** @param array<string, mixed> $row */
    private static function file(array $row): Ap2MailAttachment
    {
        return new Ap2MailAttachment(
            self::id($row['id']),
            self::id($row['mail_id']),
            self::textOrNull($row['file_id']),
            self::text($row['name']),
            self::int($row['size']),
            self::text($row['content_type']),
            self::when($row['created_at'], 'created_at'),
            self::textOrNull($row['graph_attachment_id']),
        );
    }
}
