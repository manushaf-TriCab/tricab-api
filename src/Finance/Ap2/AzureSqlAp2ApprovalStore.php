<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Approval;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalEvent;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalRef;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/** `finance.ap2_approvals` and `finance.ap2_approval_events`. */
final class AzureSqlAp2ApprovalStore extends AzureSqlAp2Support implements Ap2ApprovalStore
{
    private const COLUMNS = '[id], [kind], [retry_of], [invoice_id], [mail_id], [payment_id], [supplier_id], [subject], [summary], [snapshot],
        [requester_user_id], [requester_name], [requester_email], [approver_user_id], [approver_name], [approver_email], [status],
        [requested_at], [decided_at], [comment], [expires_at], [updated_at]';

    public function find(string $id): ?Ap2ApprovalRef
    {
        return $this->findMany([$id])[$id] ?? null;
    }

    public function findMany(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = [];
            $params = [];
            foreach ($chunk as $i => $id) {
                $marks[] = ':id' . $i;
                $params['id' . $i] = $id;
            }
            $rows = $this->connection->fetchAll(
                'SELECT [id], [kind], [status], [snapshot] FROM [finance].[ap2_approvals] WHERE [id] IN (' . implode(', ', $marks) . ')',
                $params,
            );
            foreach ($rows as $row) {
                $ref = new Ap2ApprovalRef(self::id($row['id']), self::text($row['kind']), self::text($row['status']), self::textOrNull($row['snapshot']));
                $out[$ref->id] = $ref;
            }
        }

        return $out;
    }

    public function get(string $id): ?Ap2Approval
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_approvals] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::approval($rows[0]);
    }

    public function all(): array
    {
        return array_map(self::approval(...), $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_approvals] ORDER BY [requested_at] DESC, [id] DESC'));
    }

    public function pendingFor(string $kind, string $itemId): array
    {
        $column = match ($kind) {
            'Invoice' => 'invoice_id',
            'Email' => 'mail_id',
            default => 'payment_id',
        };

        return array_map(
            self::approval(...),
            $this->connection->fetchAll(
                'SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_approvals] WHERE [kind] = :kind AND [' . $column . '] = :item AND [status] = N\'Pending\'',
                ['kind' => $kind, 'item' => $itemId],
            ),
        );
    }

    public function insert(Ap2Approval $a): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ap2_approvals] (' . self::COLUMNS . ')
             VALUES (:id, :kind, :retry_of, :invoice_id, :mail_id, :payment_id, :supplier_id, :subject, :summary, :snapshot,
                     :requester_user_id, :requester_name, :requester_email, :approver_user_id, :approver_name, :approver_email, :status,
                     :requested_at, :decided_at, :comment, :expires_at, :updated_at)',
            self::params($a),
        );
    }

    public function update(Ap2Approval $a): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ap2_approvals] SET [status] = :status, [decided_at] = :decided_at, [comment] = :comment, [updated_at] = :updated_at WHERE [id] = :id',
            ['id' => $a->id, 'status' => $a->status, 'decided_at' => self::atOrNull($a->decidedAt), 'comment' => $a->comment, 'updated_at' => self::at($a->updatedAt)],
        );
    }

    public function setSnapshotIfMissing(string $id, string $snapshot): bool
    {
        return $this->connection->fetchAll(
            'UPDATE [finance].[ap2_approvals] SET [snapshot] = :snapshot OUTPUT inserted.[id] WHERE [id] = :id AND [snapshot] IS NULL',
            ['id' => $id, 'snapshot' => $snapshot],
        ) !== [];
    }

    public function addEvent(Ap2ApprovalEvent $e): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ap2_approval_events] ([id], [approval_id], [action], [by_user_id], [by_name], [by_email], [at], [comment])
             VALUES (:id, :approval, :action, :user, :name, :email, :at, :comment)',
            [
                'id' => $e->id,
                'approval' => $e->approvalId,
                'action' => mb_substr($e->action, 0, 60),
                'user' => $e->by->userId,
                'name' => mb_substr($e->by->name, 0, 200),
                'email' => mb_substr($e->by->email, 0, 320),
                'at' => self::at($e->at),
                'comment' => $e->comment,
            ],
        );
    }

    public function events(string $approvalId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [approval_id], [action], [by_user_id], [by_name], [by_email], [at], [comment]
               FROM [finance].[ap2_approval_events] WHERE [approval_id] = :id ORDER BY [at], [id]',
            ['id' => $approvalId],
        );

        return array_map(static fn (array $row): Ap2ApprovalEvent => new Ap2ApprovalEvent(
            self::id($row['id']),
            self::id($row['approval_id']),
            self::text($row['action']),
            new Ap2Person(self::int($row['by_user_id']), self::text($row['by_name']), self::text($row['by_email'])),
            self::when($row['at'], 'at'),
            self::text($row['comment']),
        ), $rows);
    }

    /** @return array<string, scalar|null> */
    private static function params(Ap2Approval $a): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'retry_of' => $a->retryOf,
            'invoice_id' => $a->invoiceId,
            'mail_id' => $a->mailId,
            'payment_id' => $a->paymentId,
            'supplier_id' => $a->supplierId,
            'subject' => mb_substr($a->subject, 0, 400),
            'summary' => $a->summary,
            'snapshot' => $a->snapshot,
            'requester_user_id' => $a->requester->userId,
            'requester_name' => mb_substr($a->requester->name, 0, 200),
            'requester_email' => mb_substr($a->requester->email, 0, 320),
            'approver_user_id' => $a->approver->userId,
            'approver_name' => mb_substr($a->approver->name, 0, 200),
            'approver_email' => mb_substr($a->approver->email, 0, 320),
            'status' => $a->status,
            'requested_at' => self::at($a->requestedAt),
            'decided_at' => self::atOrNull($a->decidedAt),
            'comment' => $a->comment,
            'expires_at' => self::atOrNull($a->expiresAt),
            'updated_at' => self::at($a->updatedAt),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function approval(array $row): Ap2Approval
    {
        return new Ap2Approval(
            self::id($row['id']),
            self::text($row['kind']),
            new Ap2Person(self::int($row['requester_user_id']), self::text($row['requester_name']), self::text($row['requester_email'])),
            new Ap2Person(self::int($row['approver_user_id']), self::text($row['approver_name']), self::text($row['approver_email'])),
            self::when($row['requested_at'], 'requested_at'),
            self::when($row['updated_at'], 'updated_at'),
            self::textOrNull($row['invoice_id']),
            self::textOrNull($row['mail_id']),
            self::textOrNull($row['payment_id']),
            self::textOrNull($row['supplier_id']),
            self::textOrNull($row['retry_of']),
            self::text($row['subject']),
            self::text($row['summary']),
            self::textOrNull($row['snapshot']),
            self::text($row['status']),
            self::whenOrNull($row['decided_at']),
            self::text($row['comment']),
            self::whenOrNull($row['expires_at']),
        );
    }
}
