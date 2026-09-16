<?php

declare(strict_types=1);

namespace TriCab\Api\Tests;

use PHPUnit\Framework\Assert;

/**
 * Assert-and-narrow accessors for decoded JSON response payloads.
 *
 * {@see TestAppFactory::json()} can only promise array<string, mixed>, so any
 * nested reach into a payload is `mixed` as far as static analysis is
 * concerned. These helpers assert the shape and hand back a typed value, so a
 * test that walks into the wrong shape fails with a clear assertion instead of
 * a silent cast.
 */
final class Payload
{
    /**
     * @return array<string, mixed>
     */
    public static function obj(mixed $value): array
    {
        Assert::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        Assert::assertIsArray($value);

        return array_values($value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function objList(mixed $value): array
    {
        Assert::assertIsArray($value);

        $items = [];
        foreach ($value as $item) {
            Assert::assertIsArray($item);
            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Scalar coercion, matching the casts these replaced: a driver that hands
     * back '2' for an integer column still reads as 2.
     */
    public static function str(mixed $value): string
    {
        Assert::assertIsScalar($value);

        return (string) $value;
    }

    public static function int(mixed $value): int
    {
        Assert::assertIsScalar($value);

        return (int) $value;
    }

    public static function float(mixed $value): float
    {
        Assert::assertIsScalar($value);

        return (float) $value;
    }

    public static function bool(mixed $value): bool
    {
        Assert::assertIsBool($value);

        return $value;
    }

    /**
     * Reads a nested path, asserting each step is an array.
     *
     * Payload::at($payload, 'data', 'items') is the typed equivalent of
     * $payload['data']['items'].
     */
    public static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            Assert::assertIsArray($value);
            Assert::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
