<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;

final class RoleCodeTest extends TestCase
{
    public function test_accepts_and_normalizes_role_code(): void
    {
        $role = RoleCode::fromString('  tricab_admin ');

        self::assertSame('TRICAB_ADMIN', $role->toString());
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_role_codes(string $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        RoleCode::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['  '];
        yield 'starts with digit' => ['1ADMIN'];
        yield 'spaces' => ['TRI CAB'];
        yield 'hyphen' => ['TRI-CAB'];
    }

    public function test_equality(): void
    {
        $a = RoleCode::fromString('staff');
        $b = RoleCode::fromString('STAFF');
        $c = RoleCode::fromString('ADMIN');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
