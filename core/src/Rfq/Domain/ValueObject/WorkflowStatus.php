<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * Where the *people* are with an RFQ. Nothing else.
 *
 * The source has one `status` column carrying six values, and three of them
 * are not workflow at all:
 *
 * | Source status   | What it really is                        | Here                                |
 * |-----------------|------------------------------------------|-------------------------------------|
 * | `new`           | nobody has started                       | `new`                               |
 * | `in_progress`   | somebody is working it                   | `in_progress`                       |
 * | `blocked`       | a person stopped, with a reason          | `blocked`                           |
 * | `ready_to_sync` | readiness, derived on every save         | `in_progress` + derived readiness   |
 * | `failed`        | the last sync attempt failed             | `in_progress` + sync state `failed` |
 * | `synced`        | a sync attempt succeeded                 | `completed` + sync state `succeeded`|
 *
 * Folding them together is how the source let a manager set `synced` by hand
 * (`update_workflow` accepts any of the six): a quote nobody sent, reported as
 * sent. Here `completed` is set by the system on a succeeded sync and by
 * nobody else — see `WorkflowTransitionPolicy`.
 *
 * Archive is not a status. It is a flag with its own permission pair, as in
 * the source, so an archived RFQ keeps the status it was archived from.
 * `SOURCE_STATUS_MAP` is what the Phase 7 translator reads.
 */
final class WorkflowStatus extends Vocabulary
{
    public const NEW = 'new';
    public const IN_PROGRESS = 'in_progress';
    public const BLOCKED = 'blocked';
    public const COMPLETED = 'completed';

    /**
     * Source status (after the source's own `pending`/`push_failed` aliasing)
     * → workflow status here. The sync half of the meaning is carried by the
     * migrated sync attempts, not by this column.
     */
    public const SOURCE_STATUS_MAP = [
        'new' => self::NEW,
        'pending' => self::NEW,
        'in_progress' => self::IN_PROGRESS,
        'ready_to_sync' => self::IN_PROGRESS,
        'blocked' => self::BLOCKED,
        'failed' => self::IN_PROGRESS,
        'push_failed' => self::IN_PROGRESS,
        'synced' => self::COMPLETED,
    ];

    public static function values(): array
    {
        return [self::NEW, self::IN_PROGRESS, self::BLOCKED, self::COMPLETED];
    }

    /**
     * `new`: an unreadable status puts the RFQ back in front of somebody, where
     * `completed` would hide unfinished work and `blocked` would claim a reason
     * nobody gave.
     */
    protected static function fallback(): string
    {
        return self::NEW;
    }

    protected static function label(): string
    {
        return 'workflow status';
    }

    public static function new(): self
    {
        return new self(self::NEW);
    }

    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS);
    }

    public static function blocked(): self
    {
        return new self(self::BLOCKED);
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    /** Still somebody's work. Drives the open count and overdue. */
    public function isOpen(): bool
    {
        return !$this->is(self::COMPLETED);
    }
}
