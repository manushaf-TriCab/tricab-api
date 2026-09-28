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
}
