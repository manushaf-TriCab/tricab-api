<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Service\ApVendorSimilarity;

/**
 * `difflib.SequenceMatcher(None, a, b).ratio()`: every expected value below is what Python 3.14's difflib answers
 * for the same two strings, including the cases where its "popular character" rule (a second string of 200
 * characters or more) changes the answer.
 */
final class ApVendorSimilarityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, float}>
     */
    public static function pairs(): iterable
    {
        yield 'shared tail' => ['capital transport', 'civic transport', 0.75];
        yield 'equal' => ['abc', 'abc', 1.0];
        yield 'empty side' => ['', 'abc', 0.0];
        yield 'both empty' => ['', '', 1.0];
        yield 'overlap in the middle' => ['abcd', 'bcde', 0.75];
        yield 'prefix' => ['go logistics', 'go logistics nz', 0.8888888888888888];
        yield 'nothing shared' => ['a', 'b', 0.0];
        yield 'long second string, nothing popular matters' => [str_repeat('ab ', 70), str_repeat('ab ', 70) . 'c', 0.997624703087886];
        yield 'popular characters are not seeds' => ['x' . str_repeat('a', 150) . 'b', 'b' . str_repeat('a', 250) . 'x', 0.0049504950495049506];
        yield 'every character popular' => [str_repeat('transport ', 30), 'long ' . str_repeat('transport ', 40), 0.0];
    }

    #[DataProvider('pairs')]
    public function testRatioIsDifflibs(string $a, string $b, float $expected): void
    {
        self::assertSame($expected, ApVendorSimilarity::ratio($a, $b));
    }
}
