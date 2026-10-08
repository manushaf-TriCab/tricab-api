<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\Assert;

/** Small type-narrowing helper for the scan tests. */
final class FixturesView
{
    /**
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        Assert::assertIsArray($value);

        return array_values($value);
    }
}
