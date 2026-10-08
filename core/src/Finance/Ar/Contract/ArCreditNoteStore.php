<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Domain\ArCreditNote;

/** `ar_credit_note_requests`. Storage only: the token, expiry and status rules are the services'. */
interface ArCreditNoteStore
{
    /** @throws \TriCab\Core\Finance\Ar\Domain\ArConflict when the token hash is already in use */
    public function insertCreditNote(ArCreditNote $note): void;

    public function findCreditNote(string $id): ?ArCreditNote;

    /**
     * Newest first. `$customerName` is a literal "contains"; `$salesRepEmail` an exact match without regard to case.
     *
     * @return array{0: list<ArCreditNote>, 1: int} the page and the total
     */
    public function pageCreditNotes(?string $status, ?string $customerName, ?string $salesRepEmail, int $limit, int $offset): array;

    /** Writes the editable fields, the status, `reviewed_at` and the update time. Never the token or the dates a person cannot set. */
    public function updateCreditNote(ArCreditNote $note): void;

    /**
     * Gives an open request a new token (hash and expiry) and a new send date, in one conditional update. False, and
     * nothing changed, when the request is no longer open: the old link keeps its meaning.
     */
    public function replaceCreditNoteToken(string $id, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $at): bool;

    /**
     * The rep's submit, single use: the request becomes `submitted` with these values only while it is still open, in
     * one conditional update. False when another submit (or a reviewer) got there first.
     */
    public function submitCreditNote(string $id, string $invoiceNumber, float $creditAmount, string $reason, string $salesRepName, DateTimeImmutable $at): bool;
}
