<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Product\Application\Command\CreateProductVariantCommand;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Application\Exception\ProductSoftDeleted;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Domain\Entity\ProductVariant;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\Sku;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Archived parent Products MAY receive new variants (admin maintenance use
 * case — e.g. re-cataloguing a discontinued line). Soft-deleted parents
 * MUST NOT: the parent must be restored from deletion first.
 */
final class CreateProductVariantService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    public function execute(ActorContext $actor, CreateProductVariantCommand $command): ProductMutationResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductMutationResult {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $product = $this->products->findByPublicId($productPublicId);
            if ($product === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }
            if ($product->isSoftDeleted()) {
                throw ProductSoftDeleted::forPublicId($command->productPublicId);
            }

            $productId = $product->id();
            if ($productId === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }

            $variant = new ProductVariant(
                id: null,
                publicId: ProductVariantPublicId::fromString($this->uuids->generate()->toString()),
                productId: $productId,
                variantName: $command->variantName,
                status: ProductStatusCode::fromString($command->statusCode),
                displayOrder: DisplayOrder::fromInt($command->displayOrder),
                sku: $command->sku !== null ? Sku::fromString($command->sku) : null,
                variantCode: $command->variantCode !== null ? ProductCodeValue::fromString($command->variantCode) : null,
                minimumOrderQuantity: $command->minimumOrderQuantity,
                weightKg: $command->weightKg,
                packagingDescription: $command->packagingDescription,
                erpReference: $command->erpReference,
                isActive: $command->isActive,
            );

            $actorUserId = $this->audit->actorUserId($actor);
            $saved = $this->variants->save($variant, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-variant',
                entityId: $saved->id()?->toInt(),
                actionCode: 'created',
                newValues: ['variantName' => $saved->variantName(), 'sku' => $saved->sku()?->toString()],
            );

            return self::toResult($saved);
        });
    }

    public static function toResult(ProductVariant $variant): ProductMutationResult
    {
        return new ProductMutationResult(
            publicId: $variant->publicId()->toString(),
            rowVersion: $variant->rowVersion()?->toBase64() ?? '',
            statusCode: $variant->status()->toString(),
            createdAtUtc: null,
            updatedAtUtc: (new DateTimeImmutable())->format(DATE_ATOM),
        );
    }
}
