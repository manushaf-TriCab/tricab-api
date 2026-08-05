<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\ValueObject;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\CurrencyCode;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\DocumentAccessCode;
use TriCab\Core\Product\Domain\ValueObject\EffectiveDateRange;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;
use TriCab\Core\Product\Domain\ValueObject\MediaStoragePath;
use TriCab\Core\Product\Domain\ValueObject\Money;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\Sku;

final class ProductValueObjectsTest extends TestCase
{
    public function test_product_public_id_accepts_uuid(): void
    {
        $id = ProductPublicId::fromString('550e8400-e29b-41d4-a716-446655440000');
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    public function test_product_public_id_rejects_invalid(): void
    {
        $this->expectException(InvalidProductValue::class);
        ProductPublicId::fromString('not-a-uuid');
    }

    public function test_product_code_value_rejects_blank(): void
    {
        $this->expectException(InvalidProductValue::class);
        ProductCodeValue::fromString('   ');
    }

    public function test_sku_rejects_blank(): void
    {
        $this->expectException(InvalidProductValue::class);
        Sku::fromString('');
    }

    public function test_slug_requires_kebab_case(): void
    {
        $slug = ProductSlug::fromString('tri-cab-series-x');
        self::assertSame('tri-cab-series-x', $slug->toString());

        $this->expectException(InvalidProductValue::class);
        ProductSlug::fromString('Invalid Slug!');
    }

    public function test_money_formats_valid_amount(): void
    {
        $aud = CurrencyCode::fromString('AUD');
        $money = Money::of('12.50', $aud);
        self::assertSame('12.5', $money->amount());
    }

    public function test_money_rejects_negative(): void
    {
        $this->expectException(InvalidProductValue::class);
        Money::of('-1', CurrencyCode::fromString('AUD'));
    }

    public function test_money_rejects_excess_precision(): void
    {
        $this->expectException(InvalidProductValue::class);
        Money::of('1.1234567', CurrencyCode::fromString('AUD'));
    }

    public function test_effective_date_range_rejects_inverted_window(): void
    {
        $from = new DateTimeImmutable('2026-01-02T00:00:00Z');
        $to = new DateTimeImmutable('2026-01-01T00:00:00Z');

        $this->expectException(InvalidProductValue::class);
        EffectiveDateRange::of($from, $to);
    }

    public function test_display_order_rejects_negative(): void
    {
        $this->expectException(InvalidProductValue::class);
        DisplayOrder::fromInt(-1);
    }

    public function test_row_version_round_trips_base64(): void
    {
        $binary = "\x00\x00\x00\x00\x00\x00\x00\x01";
        $token = RowVersionToken::fromBinary($binary);
        self::assertSame(base64_encode($binary), $token->toBase64());
        self::assertSame($binary, RowVersionToken::fromBase64($token->toBase64())->toBinary());
    }

    public function test_row_version_rejects_invalid_base64(): void
    {
        $this->expectException(InvalidProductValue::class);
        RowVersionToken::fromBase64('abc');
    }

    public function test_image_role_code_accepts_schema_roles(): void
    {
        self::assertSame('primary', ImageRoleCode::fromString('PRIMARY')->toString());
        self::assertSame('cross-section', ImageRoleCode::fromString('cross-section')->toString());
    }

    public function test_image_role_code_rejects_registry_category(): void
    {
        $this->expectException(InvalidProductValue::class);
        ImageRoleCode::fromString('image');
    }

    public function test_document_access_code_normalizes(): void
    {
        self::assertSame('restricted', DocumentAccessCode::fromString('RESTRICTED')->toString());
    }

    public function test_document_access_code_rejects_unknown(): void
    {
        $this->expectException(InvalidProductValue::class);
        DocumentAccessCode::fromString('internal');
    }

    public function test_media_storage_path_accepts_relative_path(): void
    {
        $path = MediaStoragePath::fromString('products/abc/hero.png');
        self::assertSame('products/abc/hero.png', $path->toString());
    }

    public function test_media_storage_path_rejects_urls(): void
    {
        $this->expectException(InvalidProductValue::class);
        MediaStoragePath::fromString('https://evil.example/x');
    }

    public function test_media_storage_path_rejects_traversal(): void
    {
        $this->expectException(InvalidProductValue::class);
        MediaStoragePath::fromString('../etc/passwd');
    }

    public function test_seeded_status_and_lifecycle_codes(): void
    {
        self::assertTrue(ProductStatusCode::published()->isPublished());
        self::assertTrue(ProductStatusCode::archived()->isArchived());
        self::assertSame(
            ['draft', 'review', 'published', 'unpublished', 'discontinued', 'archived'],
            ProductStatusCode::seededCodes(),
        );
        self::assertSame(
            ['concept', 'active', 'phase-out', 'end-of-life', 'replaced'],
            ProductLifecycleStatusCode::seededCodes(),
        );
    }
}
