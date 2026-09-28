<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * A person's decision on one line: not yet reviewed, or approved.
 *
 * Only a person sets `approved` — never the parser, a reader or the agent
 * (invariant 2: model output is untrusted input). See `LineApprovalPolicy`.
 * Whether a line is *in the quote at all* is a separate question,
 * {@see LineDisposition}.
 */
final class LineApproval extends Vocabulary
{
    public const UNREVIEWED = 'unreviewed';
    public const APPROVED = 'approved';

    public static function values(): array
    {
        return [self::UNREVIEWED, self::APPROVED];
    }

    /** An approval that cannot be read was never given. */
    protected static function fallback(): string
    {
        return self::UNREVIEWED;
    }

    protected static function label(): string
    {
        return 'line approval';
    }

    public static function unreviewed(): self
    {
        return new self(self::UNREVIEWED);
    }

    public static function approved(): self
    {
        return new self(self::APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->is(self::APPROVED);
    }
}
