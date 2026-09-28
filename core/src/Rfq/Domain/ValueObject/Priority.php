<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/** RFQ priority. Ported from the source `PRIORITIES`, including its queue rank. */
final class Priority extends Vocabulary
{
    public const LOW = 'low';
    public const NORMAL = 'normal';
    public const HIGH = 'high';
    public const URGENT = 'urgent';

    /** Queue order, most pressing first (`draft_store.py` `priority_rank`). */
    private const RANK = [self::URGENT => 0, self::HIGH => 1, self::NORMAL => 2, self::LOW => 3];

    public static function values(): array
    {
        return [self::LOW, self::NORMAL, self::HIGH, self::URGENT];
    }

    /** The source reads an unknown priority as `normal`. */
    protected static function fallback(): string
    {
        return self::NORMAL;
    }

    protected static function label(): string
    {
        return 'priority';
    }

    public static function normal(): self
    {
        return new self(self::NORMAL);
    }

    public function rank(): int
    {
        return self::RANK[$this->toString()];
    }
}
