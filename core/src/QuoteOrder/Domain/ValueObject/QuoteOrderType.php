<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\ValueObject;

use InvalidArgumentException;

final readonly class QuoteOrderType
{
    public const QUOTE = 'quote';
    public const ORDER = 'order';
    public const ALL = 'all';

    public function __construct(private string $value)
    {
        if (!in_array($value, [self::QUOTE, self::ORDER, self::ALL], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported quote/order type "%s".', $value));
        }
    }

    public static function fromString(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    public static function quote(): self
    {
        return new self(self::QUOTE);
    }

    public static function order(): self
    {
        return new self(self::ORDER);
    }

    public static function all(): self
    {
        return new self(self::ALL);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function isQuote(): bool
    {
        return $this->value === self::QUOTE;
    }

    public function isOrder(): bool
    {
        return $this->value === self::ORDER;
    }

    public function isAll(): bool
    {
        return $this->value === self::ALL;
    }
}
