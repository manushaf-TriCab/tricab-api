<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

use DateTimeImmutable;

/**
 * The notification outbox (`[npr].[NotificationOutbox]` and `NotificationDeliveries`).
 * `enqueue` runs inside the workflow transition's transaction; everything else
 * runs after commit.
 */
interface NprMailOutbox
{
    public const MAX_ATTEMPTS = 5;

    /** Writes the row and one delivery per address and per missing role. Returns the outbox id. */
    public function enqueue(NewNprOutboxEntry $entry, int $activityId, int $requestId): int;

    /** @return list<NprOutboxEntry> pending rows of one request, oldest first */
    public function pendingForRequest(string $requestPublicId): array;

    /** @return list<NprOutboxEntry> pending, or failed with attempts left, oldest first; the caller applies the backoff */
    public function retryable(int $limit): array;

    public function find(int $id): ?NprOutboxEntry;

    /** @return list<NprOutboxEntry> newest first, optionally one status */
    public function recent(int $limit, ?string $status): array;

    /** Pending rows plus failed rows with attempts left (what the sweep still owes). */
    public function outstanding(): int;

    /**
     * Records one send attempt on the row and on its real (non-missing) deliveries.
     */
    public function recordAttempt(int $id, bool $sent, ?string $error, DateTimeImmutable $now): void;

    /** Admin retry: a failed or suppressed row goes back to pending with its attempts cleared. False when there is no such row. */
    public function reopen(int $id): bool;
}
