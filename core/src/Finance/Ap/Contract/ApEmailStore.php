<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailAttachment;
use TriCab\Core\Finance\Ap\Domain\ApEmailQuery;

/**
 * The AP Mailbox's emails (`finance.invoice_emails`) and the files the scan looked at (`finance.invoice_email_attachments`).
 * Storage only: the rules (what a reclassification sets, the Bin, the audit events, the mailbox scope) are
 * `ApEmailService`'s, so both adapters behave the same.
 *
 * Who calls what, for the later Tri-AP tasks (through `ApEmailService`, which wraps each):
 *
 * - AP Mailbox: {@see page()}, {@see counts()}, {@see find()} / {@see findMany()}, {@see duplicateInvoiceIdsByEmail()},
 *   {@see update()}, {@see bin()}, {@see restore()}.
 * - Scan: {@see findByMessage()} and {@see insert()} (an email is unique by mailbox and Graph message id, in any case),
 *   {@see update()} (classification, `review_status`, `proposed_response`, `scan_run_id`), {@see attachmentsOf()},
 *   {@see findAttachment()}, {@see saveAttachment()} and {@see updateAttachment()} (`invoice_id`, `duplicate_of_invoice_id`:
 *   the links between a file and an invoice). An invoice links to its email by `email_record_id`, written by the invoice insert.
 * - Mail: {@see find()} for the reply (the sender, the subject, `proposedResponse`).
 */
interface ApEmailStore
{
    /**
     * Runs `$work` as one unit: commits when it returns, rolls back when it throws, and rethrows. A call inside another
     * joins it, and so does the invoice store and the audit store on the same connection.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;

    /**
     * The page the query asks for, ordered `received_at DESC, id DESC`. An email with no `received_at` never matches a period
     * and sorts after every email that has one.
     *
     * @return list<ApEmail>
     */
    public function page(ApEmailQuery $query): array;

    /**
     * Live (not binned) emails in the period and scope, counted by classification.
     *
     * @param list<string>|null $mailboxes lower-case addresses; null is unrestricted
     * @return array{total: int, invoice: int, other: int, errors: int}
     */
    public function counts(?DateTimeImmutable $start, ?DateTimeImmutable $end, ?array $mailboxes): array;

    /** One email, live or binned; null when there is none. */
    public function find(string $id): ?ApEmail;

    /**
     * Several emails, keyed by id; an id with no email is left out.
     *
     * @param list<string> $ids
     * @return array<string, ApEmail>
     */
    public function findMany(array $ids): array;

    /** The email scanned from this Graph message, found in any case of mailbox and message id; null for none. */
    public function findByMessage(string $mailbox, string $graphMessageId): ?ApEmail;

    /**
     * @throws \TriCab\Core\Finance\Ap\Domain\ApConflict when the (mailbox, Graph message id) pair is already stored
     */
    public function insert(ApEmail $email): void;

    /**
     * Writes the named columns of one email and `updated_date`. The names must be in {@see ApEmail::UPDATABLE}: anything else
     * is a `LogicException`.
     *
     * @param array<string, mixed> $columns column name => value; a timestamp is a `DateTimeImmutable`, a flag a bool
     */
    public function update(string $id, array $columns, DateTimeImmutable $at): void;

    /** Moves a live email to the Bin; one already there is left as it is. */
    public function bin(string $id, ?int $byUserId, string $by, DateTimeImmutable $at): void;

    /** Clears the Bin columns; harmless on an email that was not binned. */
    public function restore(string $id, DateTimeImmutable $at): void;

    /**
     * For each email, the distinct invoices its files were exact copies of when the scan filed none for them: attachment rows
     * that are invoices (`is_invoice`) with no `invoice_id` and a `duplicate_of_invoice_id` whose invoice still exists, in any
     * state, oldest attachment first. ONE query for all the ids (D75). An email with none is left out.
     *
     * @param list<string> $emailIds at most 1,000
     * @return array<string, list<string>>
     */
    public function duplicateInvoiceIdsByEmail(array $emailIds): array;

    /**
     * @return list<ApEmailAttachment> oldest first
     */
    public function attachmentsOf(string $emailId): array;

    /** The file row of this Graph attachment id on this email; null for none or a blank id. */
    public function findAttachment(string $emailId, ?string $graphAttachmentId): ?ApEmailAttachment;

    /**
     * Adds a file row. A second row for the same (email, Graph attachment id) is an `ApConflict`; a row with no Graph id is
     * always added.
     *
     * @throws \TriCab\Core\Finance\Ap\Domain\ApConflict
     */
    public function saveAttachment(ApEmailAttachment $attachment): void;

    /**
     * Writes the named columns of one file row and `updated_date`; the names must be in {@see ApEmailAttachment::UPDATABLE}.
     *
     * @param array<string, mixed> $columns
     */
    public function updateAttachment(string $id, array $columns, DateTimeImmutable $at): void;
}
