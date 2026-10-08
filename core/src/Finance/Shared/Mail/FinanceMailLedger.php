<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

use DateTimeImmutable;

/**
 * The ledger of every send. A row is written as `pending` before anything is
 * sent, then becomes `sent`, `failed`, or `suppressed` (mail was switched off, so
 * nothing was sent).
 */
interface FinanceMailLedger
{
    public function findByIdempotencyKey(string $application, string $key): ?FinanceMailDelivery;

    /** One row by its id, or null. For an app that keeps its own link to a delivery (Tri-Task's mail history). */
    public function findById(string $id): ?FinanceMailDelivery;

    /**
     * Writes the pending row. False, and nothing written, when a row already
     * holds this application's idempotency key (two callers racing).
     */
    public function recordPending(FinanceMailDelivery $delivery): bool;

    public function markSent(string $id, DateTimeImmutable $at): void;

    public function markFailed(string $id, string $error): void;

    public function markSuppressed(string $id, string $reason): void;

    /**
     * Sends a `suppressed` row again: the row with `$delivery->id` takes the new
     * send's fields and goes back to `pending`. False, and nothing changed, when
     * that row is no longer `suppressed` (another caller reopened it first).
     */
    public function reopenSuppressed(FinanceMailDelivery $delivery): bool;
}
