<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use LogicException;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailAttachment;
use TriCab\Core\Finance\Ap\Domain\ApEmailQuery;

/**
 * The AP Mailbox held in memory, with the same rules as the SQL store: the tests', and a database-less run's. The invoice
 * store is asked whether an invoice still exists (a duplicate link to a deleted invoice drops out); with none given every
 * invoice is taken to exist. There is no transaction: a failure part way leaves what was written.
 */
final class InMemoryApEmailStore implements ApEmailStore
{
    /** @var array<string, ApEmail> keyed by id, in insertion order */
    private array $emails = [];

    /** @var array<string, ApEmailAttachment> keyed by id, in insertion order */
    private array $attachments = [];

    public function __construct(private readonly ?ApInvoiceStore $invoices = null)
    {
    }

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function page(ApEmailQuery $query): array
    {
        $rows = array_values(array_filter(
            $this->emails,
            static fn (ApEmail $row): bool => self::matches($row, $query->deleted, $query->classification, $query->start, $query->end, $query->mailboxes),
        ));
        usort($rows, static function (ApEmail $a, ApEmail $b): int {
            // received_at DESC with the unknown last, then id DESC.
            $order = match (true) {
                $a->receivedAt === null && $b->receivedAt === null => 0,
                $a->receivedAt === null => 1,
                $b->receivedAt === null => -1,
                default => $b->receivedAt <=> $a->receivedAt,
            };

            return $order !== 0 ? $order : strcmp($b->id, $a->id);
        });

        return array_slice($rows, max(0, $query->offset), max(1, $query->limit));
    }

    public function counts(?DateTimeImmutable $start, ?DateTimeImmutable $end, ?array $mailboxes): array
    {
        $counts = ['total' => 0, 'invoice' => 0, 'other' => 0, 'errors' => 0];
        foreach ($this->emails as $row) {
            if (!self::matches($row, false, null, $start, $end, $mailboxes)) {
                continue;
            }
            ++$counts['total'];
            match ($row->classification) {
                ApEmail::INVOICE => ++$counts['invoice'],
                ApEmail::OTHER => ++$counts['other'],
                ApEmail::ERROR => ++$counts['errors'],
                default => null,
            };
        }

        return $counts;
    }

    public function find(string $id): ?ApEmail
    {
        return $this->emails[$id] ?? null;
    }

    public function findMany(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->emails[$id])) {
                $out[$id] = $this->emails[$id];
            }
        }

        return $out;
    }

    public function findByMessage(string $mailbox, string $graphMessageId): ?ApEmail
    {
        $box = strtolower(trim($mailbox));
        foreach ($this->emails as $row) {
            if (strtolower(trim($row->mailbox)) === $box && strcasecmp($row->graphMessageId, $graphMessageId) === 0) {
                return $row;
            }
        }

        return null;
    }

    public function insert(ApEmail $email): void
    {
        if (isset($this->emails[$email->id]) || $this->findByMessage($email->mailbox, $email->graphMessageId) !== null) {
            throw new ApConflict('That message is already stored');
        }
        $this->emails[$email->id] = $email;
    }

    public function update(string $id, array $columns, DateTimeImmutable $at): void
    {
        $unknown = array_diff(array_keys($columns), ApEmail::UPDATABLE);
        if ($unknown !== []) {
            throw new LogicException('Not an updatable email column: ' . implode(', ', $unknown));
        }
        if (isset($this->emails[$id])) {
            $this->emails[$id] = $this->emails[$id]->withColumns($columns)->with(updatedAt: $at);
        }
    }

    public function bin(string $id, ?int $byUserId, string $by, DateTimeImmutable $at): void
    {
        if (isset($this->emails[$id]) && !$this->emails[$id]->isBinned()) {
            $this->emails[$id] = $this->emails[$id]->with(deletedAt: $at, deletedByUserId: $byUserId, deletedBy: $by, updatedAt: $at);
        }
    }

    public function restore(string $id, DateTimeImmutable $at): void
    {
        if (isset($this->emails[$id])) {
            $this->emails[$id] = $this->emails[$id]->with(deletedAt: null, deletedByUserId: null, deletedBy: '', updatedAt: $at);
        }
    }

    public function duplicateInvoiceIdsByEmail(array $emailIds): array
    {
        $wanted = array_flip($emailIds);
        $rows = array_values(array_filter(
            $this->attachments,
            static fn (ApEmailAttachment $a): bool => isset($wanted[$a->emailRecordId]) && $a->isInvoice && $a->invoiceId === null && $a->duplicateOfInvoiceId !== null,
        ));
        usort($rows, static fn (ApEmailAttachment $a, ApEmailAttachment $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        $out = [];
        foreach ($rows as $row) {
            $invoice = (string) $row->duplicateOfInvoiceId;
            if (($this->invoices !== null && !$this->invoices->exists($invoice)) || in_array($invoice, $out[$row->emailRecordId] ?? [], true)) {
                continue;
            }
            $out[$row->emailRecordId][] = $invoice;
        }

        return $out;
    }

    public function attachmentsOf(string $emailId): array
    {
        $rows = array_values(array_filter($this->attachments, static fn (ApEmailAttachment $a): bool => $a->emailRecordId === $emailId));
        usort($rows, static fn (ApEmailAttachment $a, ApEmailAttachment $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        return $rows;
    }

    public function findAttachment(string $emailId, ?string $graphAttachmentId): ?ApEmailAttachment
    {
        if ($graphAttachmentId === null || $graphAttachmentId === '') {
            return null;
        }
        foreach ($this->attachments as $row) {
            if ($row->emailRecordId === $emailId && $row->graphAttachmentId !== null && strcasecmp($row->graphAttachmentId, $graphAttachmentId) === 0) {
                return $row;
            }
        }

        return null;
    }

    public function saveAttachment(ApEmailAttachment $attachment): void
    {
        if (isset($this->attachments[$attachment->id]) || $this->findAttachment($attachment->emailRecordId, $attachment->graphAttachmentId) !== null) {
            throw new ApConflict('That attachment is already recorded');
        }
        $this->attachments[$attachment->id] = $attachment;
    }

    public function updateAttachment(string $id, array $columns, DateTimeImmutable $at): void
    {
        $unknown = array_diff(array_keys($columns), ApEmailAttachment::UPDATABLE);
        if ($unknown !== []) {
            throw new LogicException('Not an updatable attachment column: ' . implode(', ', $unknown));
        }
        if (isset($this->attachments[$id])) {
            $this->attachments[$id] = $this->attachments[$id]->withColumns($columns)->with(updatedAt: $at);
        }
    }

    /**
     * @param list<string>|null $mailboxes
     */
    private static function matches(ApEmail $row, bool $deleted, ?string $classification, ?DateTimeImmutable $start, ?DateTimeImmutable $end, ?array $mailboxes): bool
    {
        if ($row->isBinned() !== $deleted || ($classification !== null && $row->classification !== $classification)) {
            return false;
        }
        if (($start !== null || $end !== null) && $row->receivedAt === null) {
            return false;
        }
        if (($start !== null && $row->receivedAt < $start) || ($end !== null && $row->receivedAt >= $end)) {
            return false;
        }

        return $mailboxes === null || in_array(strtolower(trim($row->mailbox)), $mailboxes, true);
    }
}
