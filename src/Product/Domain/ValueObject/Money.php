<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Non-negative monetary amount with currency.
 * Precision limited to 6 decimal places to match DECIMAL(18,6) storage.
 */
final class Money
{
    private function __construct(
        private readonly string $amount,
        private readonly CurrencyCode $currency,
    ) {
    }

    public static function of(string|float|int $amount, CurrencyCode $currency): self
    {
        if (is_int($amount) || is_float($amount)) {
            if ($amount < 0) {
                throw InvalidProductValue::for('Money', (string) $amount);
            }
            $normalized = number_format((float) $amount, 6, '.', '');
        } else {
            $normalized = trim($amount);
            if ($normalized === '' || !is_numeric($normalized) || (float) $normalized < 0) {
                throw InvalidProductValue::for('Money', $amount);
            }
            if (preg_match('/^\d+(\.\d{1,6})?$/', $normalized) !== 1) {
                throw InvalidProductValue::for('Money', $amount);
            }
        }

        $formatted = rtrim(rtrim(number_format((float) $normalized, 6, '.', ''), '0'), '.');
        if ($formatted === '') {
            $formatted = '0';
        }

        return new self($formatted, $currency);
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): CurrencyCode
    {
        return $this->currency;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency->equals($other->currency);
    }
}
