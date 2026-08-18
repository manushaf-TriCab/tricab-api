<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Exception\InvalidProductLifecycleException;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;

final class ProductEntityTest extends TestCase
{
    public function test_create_product_requires_non_empty_name(): void
    {
        $this->expectException(InvalidProductValue::class);
        $this->makeProduct(name: '  ');
    }

    public function test_create_product_with_valid_name(): void
    {
        $product = $this->makeProduct();
        self::assertSame('TriCab Cable X', $product->productName());
        self::assertTrue($product->status()->isDraft());
        self::assertTrue($product->isActive());
        self::assertFalse($product->isArchived());
        self::assertFalse($product->isSoftDeleted());
    }

    public function test_invalid_publish_window_rejected(): void
    {
        $this->expectException(InvalidProductLifecycleException::class);
        new Product(
            id: null,
            publicId: ProductPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            productName: 'X',
            status: ProductStatusCode::draft(),
            lifecycleStatus: ProductLifecycleStatusCode::active(),
            publishAtUtc: new DateTimeImmutable('2026-02-01T00:00:00Z'),
            unpublishAtUtc: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    public function test_archive_does_not_soft_delete(): void
    {
        $product = $this->makeProduct();
        $archived = $product->archive();

        self::assertTrue($archived->isArchived());
        self::assertFalse($archived->isSoftDeleted());
        self::assertNull($archived->deletedAtUtc());
        self::assertTrue($archived->isActive());
    }

    public function test_restore_from_archive(): void
    {
        $restored = $this->makeProduct()->archive()->restoreFromArchive();
        self::assertTrue($restored->status()->isDraft());
        self::assertFalse($restored->isArchived());
    }

    public function test_soft_delete_and_restore_from_deletion_are_distinct(): void
    {
        $actor = UserId::fromInt(1);
        $deletedAt = new DateTimeImmutable('2026-08-05T00:00:00Z');
        $deleted = $this->makeProduct()->softDelete($deletedAt, $actor);

        self::assertTrue($deleted->isSoftDeleted());
        self::assertFalse($deleted->isArchived());
        self::assertSame($deletedAt, $deleted->deletedAtUtc());
        self::assertTrue($deleted->deletedByUserId()?->equals($actor) ?? false);

        $restored = $deleted->restoreFromDeletion();
        self::assertFalse($restored->isSoftDeleted());
        self::assertNull($restored->deletedAtUtc());
        self::assertTrue($restored->status()->isDraft());
    }

    public function test_soft_deleted_product_not_editable(): void
    {
        $deleted = $this->makeProduct()->softDelete(
            new DateTimeImmutable('2026-08-05T00:00:00Z'),
            UserId::fromInt(1),
        );
        $this->expectException(InvalidProductLifecycleException::class);
        $deleted->assertEditable();
    }

    public function test_archived_product_remains_editable(): void
    {
        $archived = $this->makeProduct()->archive();
        $archived->assertEditable();
        self::assertTrue($archived->isArchived());
    }

    private function makeProduct(string $name = 'TriCab Cable X'): Product
    {
        return new Product(
            id: null,
            publicId: ProductPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            productName: $name,
            status: ProductStatusCode::draft(),
            lifecycleStatus: ProductLifecycleStatusCode::concept(),
        );
    }
}
