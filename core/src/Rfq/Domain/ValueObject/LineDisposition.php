<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * Whether a line is part of the quote. An excluded line is **kept, never
 * removed** — with its reason — so the source row stays accounted for
 * (invariant 3; source `test_directives::exclusion_marks_a_row_and_never_removes_it`).
 *
 * Exclusion cannot be overridden by approval: an excluded, approved line is
 * still excluded (`test_sales_sync_safety::exclusion_cannot_be_overridden_by_approval`).
 */
final class LineDisposition extends Vocabulary
{
    public const INCLUDED = 'included';
    public const EXCLUDED = 'excluded';

    public static function values(): array
    {
        return [self::INCLUDED, self::EXCLUDED];
    }

    /**
     * `included`: a line whose disposition cannot be read stays in front of the
     * rep and in readiness, where `excluded` would silently drop a requirement.
     */
    protected static function fallback(): string
    {
        return self::INCLUDED;
    }

    protected static function label(): string
    {
        return 'line disposition';
    }

    public static function included(): self
    {
        return new self(self::INCLUDED);
    }

    public static function excluded(): self
    {
        return new self(self::EXCLUDED);
    }
}
