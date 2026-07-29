<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class UserIdTest extends TestCase
{
    public function test_accepts_positive_integer(): void
    {
        $id = UserId::fromInt(42);

        self::assertSame(42, $id->toInt());
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_non_positive_integers(int $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        UserId::fromInt($value);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidValues(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function test_equality(): void
    {
        $a = UserId::fromInt(7);
        $b = UserId::fromInt(7);
        $c = UserId::fromInt(8);

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
