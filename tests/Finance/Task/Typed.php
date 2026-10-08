<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\Assert;

/** Narrowing for values a test reads out of a decoded answer or a database row, so a wrong shape fails the test, not the type checker. */
final class Typed
{
    public static function string(mixed $value): string
    {
        Assert::assertTrue(is_string($value) || is_int($value) || is_float($value), 'a text or number was expected');

        return (string) $value;
    }

    public static function int(mixed $value): int
    {
        Assert::assertTrue(is_int($value) || (is_string($value) && is_numeric($value)), 'an integer was expected');

        return (int) $value;
    }

    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        Assert::assertIsArray($value);

        return array_values($value);
    }

    /** @return array<string, mixed> */
    public static function row(mixed $value): array
    {
        Assert::assertIsArray($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
