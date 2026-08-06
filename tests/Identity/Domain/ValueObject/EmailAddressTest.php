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

    public function test_rejects_email_longer_than_320_characters(): void
    {
        $email = str_repeat('a', 310) . '@example.com';
        self::assertGreaterThan(320, strlen($email));

        $this->expectException(InvalidIdentityValue::class);
        EmailAddress::fromString($email);
    }

    public function test_accepts_long_valid_email_within_maximum_length(): void
    {
        // RFC practical maximum accepted by FILTER_VALIDATE_EMAIL is 254.
        $domain = implode('.', [
            str_repeat('b', 63),
            str_repeat('b', 63),
            str_repeat('b', 61),
        ]);
        $email = str_repeat('a', 64) . '@' . $domain;
        self::assertSame(254, strlen($email));
        self::assertLessThanOrEqual(320, strlen($email));

        $value = EmailAddress::fromString($email);
        self::assertSame(strtolower($email), $value->toString());
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
