<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;

final class ExternalProviderTest extends TestCase
{
    public function test_accepts_and_normalizes_microsoft_entra(): void
    {
        $provider = ExternalProvider::fromString('  Microsoft_Entra ');

        self::assertSame(ExternalProvider::MICROSOFT_ENTRA, $provider->toString());
        self::assertTrue($provider->isMicrosoftEntra());
    }

    public function test_named_constructor(): void
    {
        $provider = ExternalProvider::microsoftEntra();

        self::assertTrue($provider->isMicrosoftEntra());
        self::assertTrue($provider->equals(ExternalProvider::fromString('microsoft_entra')));
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_unknown_providers(string $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        ExternalProvider::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'google' => ['google'];
        yield 'okta' => ['okta'];
    }

    public function test_equality(): void
    {
        $a = ExternalProvider::microsoftEntra();
        $b = ExternalProvider::fromString('microsoft_entra');

        self::assertTrue($a->equals($b));
    }
}
