<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;

/**
 * A durable unit of background work, and its lease. Contract `rfq.job.v1`.
 *
 * One shape for every RFQ worker — parse, read, notify, sync, mail — so claim,
 * lease expiry, bounded attempts and recovery are written once (plan 2.4). The
 * source had four hand-rolled versions: a whole-poll file lock for mail, no
 * claim at all for notifications, a select-then-update for reader jobs, and a
 * locked row for sync attempts. Only the last is safe with two workers.
 *
 * A claim is `leaseOwner` + `leaseExpiresAtUtc`, written in the same statement
 * that checks the job is still claimable. An expired lease is recoverable by
 * another worker; the type-specific machine decides what recovery means (a
 * sync attempt is swept, never blindly re-run).
 *
 * Not serialised to the browser. Job progress reaches a person through the
 * type's own view — {@see ReaderResult}, {@see SyncAttemptView}.
 */
final class RfqJob
{
    public const TYPE_PARSE = 'parse';
    public const TYPE_READ = 'read';
    public const TYPE_NOTIFY = 'notify';
    public const TYPE_SYNC = 'sync';
    public const TYPE_MAIL = 'mail';

    public const TYPES = [self::TYPE_PARSE, self::TYPE_READ, self::TYPE_NOTIFY, self::TYPE_SYNC, self::TYPE_MAIL];

    public function __construct(
        public readonly string $publicId,
        public readonly string $type,
        public readonly ?string $rfqPublicId,
        public readonly string $dedupeKey,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $leaseOwner,
        public readonly ?DateTimeImmutable $leaseExpiresAtUtc,
        public readonly DateTimeImmutable $availableAtUtc,
    ) {
    }

    public function isLeaseExpired(DateTimeImmutable $nowUtc): bool
    {
        return $this->leaseExpiresAtUtc !== null && $this->leaseExpiresAtUtc <= $nowUtc;
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }
}
