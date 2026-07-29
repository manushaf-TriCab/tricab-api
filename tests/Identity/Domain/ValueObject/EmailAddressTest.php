<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

final class EmailAddressTest extends TestCase
{
    public function test_accepts_and_normalizes_email(): void
    {
        $email = EmailAddress::fromString('  Jane.Doe@Example.COM ');

        self::assertSame('jane.doe@example.com', $email->toString());
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_emails(string $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        EmailAddress::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['  '];
        yield 'missing at' => ['jane.example.com'];
        yield 'missing domain' => ['jane@'];
        yield 'spaces' => ['jane doe@example.com'];
    }

    public function test_equality_is_case_insensitive_after_normalization(): void
    {
        $a = EmailAddress::fromString('user@example.com');
        $b = EmailAddress::fromString('USER@EXAMPLE.COM');
        $c = EmailAddress::fromString('other@example.com');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
