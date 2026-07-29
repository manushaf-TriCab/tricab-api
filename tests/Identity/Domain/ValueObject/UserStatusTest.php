<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class UserStatusTest extends TestCase
{
    #[DataProvider('allowedStatuses')]
    public function test_accepts_and_normalizes_allowed_statuses(string $input, string $expected): void
    {
        $status = UserStatus::fromString($input);

        self::assertSame($expected, $status->toString());
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedStatuses(): iterable
    {
        yield 'active' => ['  ACTIVE ', UserStatus::ACTIVE];
        yield 'pending' => ['Pending', UserStatus::PENDING];
        yield 'disabled' => ['disabled', UserStatus::DISABLED];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_unknown_statuses(string $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        UserStatus::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'suspended' => ['suspended'];
        yield 'deleted' => ['deleted'];
    }

    public function test_named_constructors(): void
    {
        self::assertTrue(UserStatus::active()->isActive());
        self::assertTrue(UserStatus::pending()->isPending());
        self::assertTrue(UserStatus::disabled()->isDisabled());
    }

    public function test_only_active_allows_authentication(): void
    {
        self::assertTrue(UserStatus::active()->allowsAuthentication());
        self::assertFalse(UserStatus::pending()->allowsAuthentication());
        self::assertFalse(UserStatus::disabled()->allowsAuthentication());
    }

    public function test_equality(): void
    {
        $a = UserStatus::fromString('active');
        $b = UserStatus::active();
        $c = UserStatus::disabled();

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
