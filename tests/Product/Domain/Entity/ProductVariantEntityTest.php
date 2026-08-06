<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\Entity\ProductVariant;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

final class ProductVariantEntityTest extends TestCase
{
    public function test_variant_is_separate_aggregate_with_own_archive_semantics(): void
    {
        $variant = new ProductVariant(
            id: null,
            publicId: ProductVariantPublicId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            productId: ProductId::fromInt(10),
            variantName: '16mm Black',
            status: ProductStatusCode::draft(),
            displayOrder: DisplayOrder::fromInt(0),
        );

        $archived = $variant->archive();
        self::assertTrue($archived->isArchived());
        self::assertFalse($archived->isSoftDeleted());
        self::assertSame(10, $archived->productId()->toInt());

        $deleted = $variant->softDelete(
            new DateTimeImmutable('2026-08-05T00:00:00Z'),
            UserId::fromInt(2),
        );
        self::assertTrue($deleted->isSoftDeleted());
        self::assertFalse($deleted->isArchived());
    }
}
