<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class UserPublicIdTest extends TestCase
{
    public function test_accepts_and_normalizes_uuid(): void
    {
        $id = UserPublicId::fromString(' 550E8400-E29B-41D4-A716-446655440000 ');

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    public function test_accepts_sql_server_sequential_uuid(): void
    {
        // Azure SQL NEWSEQUENTIALID() values are not RFC 4122 version/variant compliant.
        $id = UserPublicId::fromString('59A45BFB-AC8F-F111-B337-6045BD3F9734');

        self::assertSame('59a45bfb-ac8f-f111-b337-6045bd3f9734', $id->toString());
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_values(string $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        UserPublicId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'not uuid' => ['not-a-uuid'];
        yield 'missing segments' => ['550e8400-e29b-41d4-a716'];
    }

    public function test_equality(): void
    {
        $a = UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = UserPublicId::fromString('550E8400-E29B-41D4-A716-446655440000');
        $c = UserPublicId::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
