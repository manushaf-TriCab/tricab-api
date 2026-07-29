<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

final class ExternalTenantIdTest extends TestCase
{
    public function test_accepts_and_normalizes_tenant_uuid(): void
    {
        $tenant = ExternalTenantId::fromString(' 72F988BF-86F1-41AF-91AB-2D7CD011DB47 ');

        self::assertSame('72f988bf-86f1-41af-91ab-2d7cd011db47', $tenant->toString());
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_tenant_ids(string $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        ExternalTenantId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'not uuid' => ['contoso'];
        yield 'partial' => ['72f988bf-86f1-41af-91ab'];
    }

    public function test_equality(): void
    {
        $a = ExternalTenantId::fromString('72f988bf-86f1-41af-91ab-2d7cd011db47');
        $b = ExternalTenantId::fromString('72F988BF-86F1-41AF-91AB-2D7CD011DB47');
        $c = ExternalTenantId::fromString('00000000-0000-4000-8000-000000000000');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
