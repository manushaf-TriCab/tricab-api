<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Contract\ApScanStore;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApScanRun;

/** Scan runs held in memory, with the one-open-scan claim the filtered unique index gives the SQL. */
final class InMemoryApScanStore implements ApScanStore
{
    public const CLAIM_CONFLICT = 'Another scan is in progress. Wait for it to finish, or stop it, before starting a new one.';

    /** @var array<string, ApScanRun> */
    private array $runs = [];

    public function begin(ApScanRun $run, DateTimeImmutable $now): void
    {
        foreach ($this->runs as $id => $other) {
            if (!$other->isOpen()) {
                continue;
            }
            if ($other->claimExpiresAt !== null && $other->claimExpiresAt > $now) {
                throw new ApConflict(self::CLAIM_CONFLICT);
            }
            $this->runs[$id] = $other->with(status: ApScanRun::FAILED, claimExpiresAt: null, updatedAt: $now);
        }
        $this->runs[$run->id] = $run;
    }

    public function find(string $id): ?ApScanRun
    {
        return $this->runs[$id] ?? null;
    }

    public function markRunning(string $id, int $totalMessages, int $errors, DateTimeImmutable $claimUntil, DateTimeImmutable $at): void
    {
        $run = $this->runs[$id] ?? null;
        if ($run === null) {
            return;
        }
        $this->runs[$id] = $run->with(status: ApScanRun::RUNNING, totalMessages: $totalMessages, errors: $errors, claimExpiresAt: $claimUntil, updatedAt: $at);
    }

    public function bump(string $id, array $increments, DateTimeImmutable $claimUntil, DateTimeImmutable $at): void
    {
        $run = $this->runs[$id] ?? null;
        if ($run === null) {
            return;
        }
        $this->runs[$id] = $run->with(
            processedMessages: $run->processedMessages + ($increments['processed_messages'] ?? 0),
            invoiceEmails: $run->invoiceEmails + ($increments['invoice_emails'] ?? 0),
            otherEmails: $run->otherEmails + ($increments['other_emails'] ?? 0),
            duplicateInvoices: $run->duplicateInvoices + ($increments['duplicate_invoices'] ?? 0),
            errors: $run->errors + ($increments['errors'] ?? 0),
            claimExpiresAt: $run->isOpen() ? $claimUntil : $run->claimExpiresAt,
            updatedAt: $at,
        );
    }

    public function finish(string $id, string $status, DateTimeImmutable $at, ?int $errors = null): void
    {
        $run = $this->runs[$id] ?? null;
        if ($run === null) {
            return;
        }
        $this->runs[$id] = $run->with(status: $status, claimExpiresAt: null, updatedAt: $at, errors: $errors ?? $run->errors);
    }

    public function overlapping(string $from, string $to, int $limit = 20): array
    {
        $found = array_values(array_filter(
            $this->runs,
            static fn (ApScanRun $r): bool => $r->fromDate !== null && $r->toDate !== null
                && $r->status !== ApScanRun::FAILED && $r->totalMessages > 0
                && $r->fromDate <= $to && $r->toDate >= $from,
        ));
        usort($found, static fn (ApScanRun $a, ApScanRun $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return array_slice($found, 0, $limit);
    }
}
