<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;

/**
 * A draft that cannot take a content save right now. Maps to 409. The
 * source's `DraftSyncLocked` and its archived-draft `ValueError`.
 */
final class RfqDraftNotEditable extends RuntimeException
{
    public const SYNC_RUNNING = 'sync_running';
    public const ARCHIVED = 'archived';
    public const NOT_ARCHIVED = 'not_archived';
    public const ALREADY_ASSIGNED = 'already_assigned';
    public const ALREADY_SYNCED = 'already_synced';
    public const SYNC_BLOCKED = 'sync_blocked';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function syncRunning(): self
    {
        return new self(self::SYNC_RUNNING, 'A sync for this RFQ is running. Wait for it to finish before editing.');
    }

    public static function archived(): self
    {
        return new self(self::ARCHIVED, 'Archived RFQs cannot be edited.');
    }

    public static function notArchived(): self
    {
        return new self(self::NOT_ARCHIVED, 'This RFQ is not archived.');
    }

    /** A sync of a quote that is already in 4js, with no change since (parity plan E7.3). */
    public static function alreadySynced(?string $refno, ?string $refver): self
    {
        return new self(self::ALREADY_SYNCED, sprintf(
            'Quote %s (v%s) is in 4js. Further changes belong in 4js: syncing again from here would resend every line as a new version and bury them.',
            $refno ?? '',
            $refver !== null && $refver !== '' ? $refver : '1',
        ));
    }

    /** What the source's `acquire_sync_lock` refuses with: a sync already running, or reads still to settle. */
    public static function syncBlocked(string $message): self
    {
        return new self(self::SYNC_BLOCKED, $message);
    }

    public static function alreadyAssigned(): self
    {
        return new self(self::ALREADY_ASSIGNED, 'This RFQ has already been claimed. Reload it.');
    }
}
