<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;

final class PermissionCodeTest extends TestCase
{
    public function test_accepts_and_normalizes_permission_code(): void
    {
        $permission = PermissionCode::fromString('  technical.articles:read ');

        self::assertSame('TECHNICAL.ARTICLES:READ', $permission->toString());
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_permission_codes(string $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        PermissionCode::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['  '];
        yield 'starts with digit' => ['1READ'];
        yield 'spaces' => ['TECH READ'];
        yield 'slash' => ['TECH/READ'];
    }

    public function test_equality(): void
    {
        $a = PermissionCode::fromString('orders:write');
        $b = PermissionCode::fromString('ORDERS:WRITE');
        $c = PermissionCode::fromString('ORDERS:READ');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
