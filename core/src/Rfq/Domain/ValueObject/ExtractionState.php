<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * Whether the RFQ's documents have been read, as a whole. Derived, never set:
 * see `ExtractionStatePolicy::derive()`.
 *
 *  - `reading`    — a read is queued or running.
 *  - `attention`  — a read failed, or offers a proposal, and nobody has
 *                   resolved it. Blocks the sync (source `reader_state_issues`).
 *  - `partial`    — every read settled, but coverage accounting says input rows
 *                   are unaccounted for. Blocks the sync (invariant 3: partial
 *                   extraction cannot become ready because the rows it did
 *                   return happen to match).
 *  - `complete`   — every read settled and every input row is accounted for.
 *
 * An RFQ typed or pasted with no document at all is `complete`: there is
 * nothing left to read.
 */
final class ExtractionState extends Vocabulary
{
    public const READING = 'reading';
    public const ATTENTION = 'attention';
    public const PARTIAL = 'partial';
    public const COMPLETE = 'complete';

    public static function values(): array
    {
        return [self::READING, self::ATTENTION, self::PARTIAL, self::COMPLETE];
    }

    /** `attention`: an unreadable state must stop the sync, not wave it through. */
    protected static function fallback(): string
    {
        return self::ATTENTION;
    }

    protected static function label(): string
    {
        return 'extraction state';
    }

    public static function reading(): self
    {
        return new self(self::READING);
    }

    public static function attention(): self
    {
        return new self(self::ATTENTION);
    }

    public static function partial(): self
    {
        return new self(self::PARTIAL);
    }

    public static function complete(): self
    {
        return new self(self::COMPLETE);
    }

    public function blocksSync(): bool
    {
        return !$this->is(self::COMPLETE);
    }
}
