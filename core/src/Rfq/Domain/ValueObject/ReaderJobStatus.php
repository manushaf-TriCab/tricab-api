<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * One background read of one source document.
 *
 * The source (`rfq_reader_jobs.status`) has `queued`, `running`, `done`,
 * `failed`, `superseded` and `dismissed`, and `done` means two different
 * things depending on whether a `proposal` is attached:
 *
 *  - the draft was untouched, so the read **replaced** its rows → `applied`;
 *  - the rep had edited, so the read became a **proposal** they must accept
 *    or dismiss → `proposed`, and after accepting → `accepted`.
 *
 * They are split here because they are opposite answers to "does anybody
 * need to look at this?", and readiness depends on that answer: a standing
 * proposal or an unacknowledged failure blocks the sync.
 */
final class ReaderJobStatus extends Vocabulary
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const APPLIED = 'applied';
    public const PROPOSED = 'proposed';
    public const ACCEPTED = 'accepted';
    public const DISMISSED = 'dismissed';
    public const FAILED = 'failed';
    public const SUPERSEDED = 'superseded';

    /** Still going. */
    public const IN_FLIGHT = [self::QUEUED, self::RUNNING];

    /** Waiting on a person. Blocks the sync. */
    public const NEEDS_ATTENTION = [self::PROPOSED, self::FAILED];

    /** Nothing more will happen to it. */
    public const SETTLED = [self::APPLIED, self::ACCEPTED, self::DISMISSED, self::SUPERSEDED];

    public static function values(): array
    {
        return [
            self::QUEUED,
            self::RUNNING,
            self::APPLIED,
            self::PROPOSED,
            self::ACCEPTED,
            self::DISMISSED,
            self::FAILED,
            self::SUPERSEDED,
        ];
    }

    /**
     * `failed`: a read whose state cannot be read is one somebody has to look
     * at. `applied` would say the rows arrived when nobody knows that.
     */
    protected static function fallback(): string
    {
        return self::FAILED;
    }

    protected static function label(): string
    {
        return 'reader job status';
    }

    public function isInFlight(): bool
    {
        return $this->isOneOf(self::IN_FLIGHT);
    }

    public function needsAttention(): bool
    {
        return $this->isOneOf(self::NEEDS_ATTENTION);
    }

    public function isSettled(): bool
    {
        return $this->isOneOf(self::SETTLED);
    }
}
