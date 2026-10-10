<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use LogicException;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceQuery;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;

/**
 * The register held in memory, with the same rules as the SQL store: the tests', and a database-less run's. The scan's
 * tombstones and the scanned emails' sender names are held too, so the rules that read them can be tested. There is no
 * transaction: a failure part way leaves what was written, which is why the SQL store is the one to trust for atomicity.
 */
final class InMemoryApInvoiceStore implements ApInvoiceStore
{
    /** @var array<string, ApInvoice> keyed by id, in insertion order */
    private array $rows = [];

    /** @var array<string, true> `emailId\0key` of every tombstone */
    private array $skips = [];

    /** @var array<string, string> sender name by email record id */
    private array $senderByRecord = [];

    /** @var array<string, string> sender name by Graph message id */
    private array $senderByMessage = [];

    /** @var list<string> the ids purged, in order: what a test checks the children cleanup was asked for */
    public array $purged = [];

    /** Adds a scanned email's sender name, found for an invoice by its `email_record_id` or its Graph message id. */
    public function withEmail(string $recordId, string $graphMessageId, string $senderName): self
    {
        $this->senderByRecord[$recordId] = $senderName;
        $this->senderByMessage[$graphMessageId] = $senderName;

        return $this;
    }

    /** @return list<array{0: string, 1: string}> every tombstone as [email id, key] */
    public function skips(): array
    {
        $out = [];
        foreach (array_keys($this->skips) as $key) {
            [$emailId, $skipKey] = explode("\0", (string) $key, 2) + ['', ''];
            $out[] = [$emailId, $skipKey];
        }

        return $out;
    }

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function page(ApInvoiceQuery $query): array
    {
        $rows = array_values(array_filter($this->rows, fn (ApInvoice $row): bool => $this->matches($row, $query)));
        $direction = $query->descending ? -1 : 1;
        usort($rows, function (ApInvoice $a, ApInvoice $b) use ($query, $direction): int {
            $order = self::compare($this->sortValue($a, $query), $this->sortValue($b, $query)) * $direction;

            return $order !== 0 ? $order : strcmp($a->id, $b->id) * $direction;
        });

        return array_map($this->named(...), array_slice($rows, 0, max(1, $query->limit)));
    }

    public function find(string $id): ?ApInvoice
    {
        return isset($this->rows[$id]) ? $this->named($this->rows[$id]) : null;
    }

    public function findMany(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                $out[$id] = $this->named($this->rows[$id]);
            }
        }

        return $out;
    }

    public function exists(string $id): bool
    {
        return isset($this->rows[$id]);
    }

    public function insert(ApInvoice $invoice): void
    {
        $this->rows[$invoice->id] = $invoice->with(senderName: '');
    }

    public function update(string $id, array $columns, DateTimeImmutable $at): void
    {
        $unknown = array_diff(array_keys($columns), ApInvoice::PATCHABLE);
        if ($unknown !== []) {
            throw new LogicException('Not an editable column: ' . implode(', ', $unknown));
        }
        if (isset($this->rows[$id])) {
            $this->rows[$id] = $this->rows[$id]->withColumns($columns)->with(updatedAt: $at);
        }
    }

    public function bin(string $id, ?int $byUserId, string $by, DateTimeImmutable $at): void
    {
        if (isset($this->rows[$id]) && $this->rows[$id]->deletedAt === null) {
            $this->rows[$id] = $this->rows[$id]->with(deletedAt: $at, deletedByUserId: $byUserId, deletedBy: $by, updatedAt: $at);
        }
    }

    public function restore(string $id, DateTimeImmutable $at): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = $this->rows[$id]->with(deletedAt: null, deletedByUserId: null, deletedBy: '', updatedAt: $at);
        }
    }

    public function purge(string $id, ?string $emailId, ?string $skipKey, DateTimeImmutable $at): void
    {
        foreach ($this->rows as $key => $row) {
            if ($row->duplicateOfInvoiceId === $id) {
                $this->rows[$key] = $row->with(duplicateOfInvoiceId: null);
            }
        }
        if ($emailId !== null && $skipKey !== null) {
            $this->skips[$emailId . "\0" . $skipKey] = true;
        }
        unset($this->rows[$id]);
        $this->purged[] = $id;
    }

    public function findByContentHash(string $hash): ?ApInvoice
    {
        if ($hash === '') {
            return null;
        }

        return $this->oldest(fn (ApInvoice $row): bool => $row->contentHash !== null && strcasecmp($row->contentHash, $hash) === 0);
    }

    public function findBusinessDuplicate(string $invoiceNo, string $vendor, string $currency, float $total): ?ApInvoice
    {
        return $this->oldest(static fn (ApInvoice $row): bool => mb_strtolower(trim($row->invoiceNo)) === mb_strtolower($invoiceNo)
            && mb_strtolower(trim($row->vendor)) === mb_strtolower($vendor)
            && strtoupper($row->currency === '' ? 'AUD' : $row->currency) === strtoupper($currency)
            && (int) round(($row->totalAmount ?? 0.0) * 100) === (int) round($total * 100));
    }

    public function findCaptured(string $emailId, ?string $attachmentId, string $name): ?ApInvoice
    {
        if ($attachmentId !== null && $attachmentId !== '') {
            $hit = $this->oldest(static fn (ApInvoice $row): bool => $row->emailId === $emailId && $row->attachmentId === $attachmentId);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $this->oldest(static fn (ApInvoice $row): bool => $row->emailId === $emailId && $row->attachment === $name
            && ($row->attachmentId === null || $row->attachmentId === ''));
    }

    public function scanSkipped(string $emailId, ?string $attachmentId, string $name): bool
    {
        foreach ([$attachmentId, $name] as $key) {
            if ($key !== null && $key !== '' && isset($this->skips[$emailId . "\0" . $key])) {
                return true;
            }
        }

        return false;
    }

    public function invoiceIdsByEmail(array $emailRecordIds): array
    {
        $rows = array_filter($this->rows, static fn (ApInvoice $row): bool => $row->emailRecordId !== null && in_array($row->emailRecordId, $emailRecordIds, true));
        usort($rows, static fn (ApInvoice $a, ApInvoice $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->emailRecordId][] = $row->id;
        }

        return $out;
    }

    public function setVendorMatch(string $id, ApVendorMatch $match, ?int $byUserId, DateTimeImmutable $at): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = $this->rows[$id]->with(
                odooVendorId: $match->odooVendorId,
                vendorRef: $match->vendorRef ?? '',
                odooVendorName: $match->odooVendorName ?? '',
                vendorMatch: $match->vendorMatch,
                vendorMatchNote: $match->vendorMatchNote,
                vendorMatchByUserId: $byUserId,
                updatedAt: $at,
            );
        }
    }

    public function liveCountsByVendor(): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            if ($row->odooVendorId !== null && $row->deletedAt === null) {
                $counts[$row->odooVendorId] = ($counts[$row->odooVendorId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function setApprovalStatus(array $ids, string $status, DateTimeImmutable $at): void
    {
        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                $this->rows[$id] = $this->rows[$id]->with(approvalStatus: $status, updatedAt: $at);
            }
        }
    }

    // ---- internals -------------------------------------------------------------------

    /** The row with the sender's display name found from the scanned email: by record id, else by message id. */
    private function named(ApInvoice $row): ApInvoice
    {
        $name = ($row->emailRecordId !== null ? ($this->senderByRecord[$row->emailRecordId] ?? '') : '');
        if ($name === '' && $row->emailId !== null) {
            $name = $this->senderByMessage[$row->emailId] ?? '';
        }

        return $row->with(senderName: $name);
    }

    /**
     * @param callable(ApInvoice): bool $test
     */
    private function oldest(callable $test): ?ApInvoice
    {
        $hits = array_values(array_filter($this->rows, $test));
        usort($hits, static fn (ApInvoice $a, ApInvoice $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        return $hits === [] ? null : $this->named($hits[0]);
    }

    private function matches(ApInvoice $row, ApInvoiceQuery $query): bool
    {
        if ($query->deleted !== ($row->deletedAt !== null)) {
            return false;
        }
        if ($query->mailboxes !== null && !$this->inScope($row, $query->mailboxes)) {
            return false;
        }
        $basis = $row->emailDate ?? $row->createdAt;
        if ($query->start !== null && $basis < $query->start) {
            return false;
        }
        if ($query->end !== null && $basis >= $query->end) {
            return false;
        }
        if ($query->id !== null && $row->id !== $query->id) {
            return false;
        }

        return $query->status === null || $row->shownStatus($query->today) === $query->status;
    }

    /**
     * @param list<string> $mailboxes
     */
    private function inScope(ApInvoice $row, array $mailboxes): bool
    {
        $mailbox = strtolower(trim((string) $row->mailbox));

        return $mailbox === '' || in_array($mailbox, $mailboxes, true);
    }

    private function sortValue(ApInvoice $row, ApInvoiceQuery $query): mixed
    {
        return match ($query->sortBy) {
            'id' => $row->id,
            'updated_date' => $row->updatedAt,
            'email_date' => $row->emailDate,
            'invoice_date' => $row->invoiceDate,
            'due_date' => $row->dueDate,
            'deleted_at' => $row->deletedAt,
            'status' => $row->shownStatus($query->today),
            'vendor' => $row->vendor,
            'invoice_no' => $row->invoiceNo,
            'mailbox' => $row->mailbox,
            'company' => $row->company,
            'currency' => $row->currency,
            'net_amount' => $row->netAmount,
            'tax_amount' => $row->taxAmount,
            'total_amount' => $row->totalAmount,
            'cost_centre' => $row->costCentre,
            'account_category' => $row->accountCategory,
            'approval_status' => $row->approvalStatus,
            'duplicate_status' => $row->duplicateStatus,
            'vendor_match' => $row->vendorMatch,
            'odoo_vendor_name' => $row->odooVendorName,
            'po_number' => $row->poNumber,
            'tax_id' => $row->taxId,
            'vendor_email' => $row->vendorEmail,
            'sender' => $row->sender,
            'subject' => $row->subject,
            'attachment' => $row->attachment,
            default => $row->createdAt,
        };
    }

    /** Null sorts first, as it does in SQL Server; text compares without regard to case. */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1);
        }
        if ($a instanceof DateTimeImmutable && $b instanceof DateTimeImmutable) {
            return $a <=> $b;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp(mb_strtolower($a), mb_strtolower($b));
        }

        return $a <=> $b;
    }
}
