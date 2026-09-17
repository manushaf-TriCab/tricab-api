<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\ValueObject;

/**
 * Quote/order money total. Legacy tnet of 0.00 is TBC → amount null + pending
 * (compatible with cart null-money pattern; never the string "TBC").
 */
final readonly class MoneyTotal
{
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_PENDING = 'pending';

    public function __construct(
        public ?string $amount,
        public string $status,
    ) {
    }

    public static function fromLegacyNet(string|int|float|null $net): self
    {
        if ($net === null || $net === '') {
            return new self(null, self::STATUS_PENDING);
        }

        $normalized = is_string($net) ? trim($net) : (string) $net;
        if ($normalized === '' || bccomp($normalized, '0', 2) === 0) {
            return new self(null, self::STATUS_PENDING);
        }

        return new self(self::formatAmount($normalized), self::STATUS_RESOLVED);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    private static function formatAmount(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }
}
