<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * How far an actor's RFQ reads reach: their own, their branch's, or all.
 *
 * Resolved once per request by `RfqAccessPolicy::effectiveScope()` from the
 * two scope permissions and the actor's branch membership, never taken from a
 * client. Ordered narrowest first.
 */
final class RfqScope extends Vocabulary
{
    public const OWN = 'own';
    public const BRANCH = 'branch';
    public const ALL = 'all';

    public static function values(): array
    {
        return [self::OWN, self::BRANCH, self::ALL];
    }

    /** Narrowest. A scope that cannot be read grants the least. */
    protected static function fallback(): string
    {
        return self::OWN;
    }

    protected static function label(): string
    {
        return 'RFQ scope';
    }

    public static function own(): self
    {
        return new self(self::OWN);
    }

    public static function branch(): self
    {
        return new self(self::BRANCH);
    }

    public static function all(): self
    {
        return new self(self::ALL);
    }
}
