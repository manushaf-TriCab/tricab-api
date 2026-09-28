<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/**
 * A closed set of lower-case string values: a status, a scope, a channel.
 *
 * Two readers, deliberately different:
 *
 *  - {@see fromString()} is for values arriving from a client, and refuses
 *    anything outside the set. A client sending `definitely_synced` has a bug
 *    that silence would hide.
 *  - {@see fromStorage()} is for values read back from a row, and falls back to
 *    the subclass's safest reading. A hand-edited or pre-rename row must not
 *    make a whole queue unreadable. What "safest" means is per vocabulary, and
 *    each subclass says why in {@see fallback()}.
 *
 * Source aliases (`pending` → `new`, `push_failed` → `failed`) are the one
 * place storage values are rewritten, through {@see aliases()}, so the old
 * spellings keep reading correctly after migration without a data rewrite.
 */
abstract class Vocabulary
{
    final protected function __construct(private readonly string $value)
    {
    }

    /** @return list<string> */
    abstract public static function values(): array;

    /** The value an unreadable stored value is read as. */
    abstract protected static function fallback(): string;

    /** A short name for error messages, such as `workflow status`. */
    abstract protected static function label(): string;

    /** @return array<string, string> old spelling => current value */
    protected static function aliases(): array
    {
        return [];
    }

    public static function fromString(string $value): static
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, static::values(), true)) {
            throw InvalidRfqValue::forOneOf(static::label(), $value, static::values());
        }

        return new static($normalized);
    }

    public static function fromStorage(mixed $value): static
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            $normalized = static::aliases()[$normalized] ?? $normalized;

            if (in_array($normalized, static::values(), true)) {
                return new static($normalized);
            }
        }

        return new static(static::fallback());
    }

    /** @return list<static> */
    public static function cases(): array
    {
        return array_map(static fn (string $value): static => new static($value), static::values());
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function is(string $value): bool
    {
        return $this->value === $value;
    }

    /** @param list<string> $values */
    public function isOneOf(array $values): bool
    {
        return in_array($this->value, $values, true);
    }

    public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }
}
