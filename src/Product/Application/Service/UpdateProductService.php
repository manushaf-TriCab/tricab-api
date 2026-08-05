<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpdateProductCommand;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\InternalProductCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Merge-patch update. Loads the current Product, applies only the fields the
 * caller supplied, and persists via Product::withContent().
 */
final class UpdateProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductClassificationRepository $classification,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, UpdateProductCommand $command): ProductMutationResult
    {
        if ($command->updateClassification) {
            $this->classification->assertHierarchyValid(
                $command->familyPublicId,
                $command->categoryPublicId,
                $command->seriesPublicId,
            );
        }

        return $this->transaction->run(function () use ($actor, $command): ProductMutationResult {
            $publicId = ProductPublicId::fromString($command->publicId);
            $existing = $this->products->findByPublicId($publicId);
            if ($existing === null) {
                throw ProductNotFound::forPublicId($command->publicId);
            }

            $existing->assertEditable();

            $updated = $existing->withContent(
                productName: $command->productName ?? $existing->productName(),
                internalName: $command->internalName ?? $existing->internalName(),
                internalCode: $command->internalCode !== null
                    ? InternalProductCode::fromString($command->internalCode)
                    : $existing->internalCode(),
                shortDescription: $command->shortDescription ?? $existing->shortDescription(),
                fullDescription: $command->fullDescription ?? $existing->fullDescription(),
                legacyProductId: $command->legacyProductId ?? $existing->legacyProductId(),
                erpReference: $command->erpReference ?? $existing->erpReference(),
                isFeatured: $command->isFeatured ?? $existing->isFeatured(),
                publishAtUtc: $command->publishAtUtc !== null
                    ? new DateTimeImmutable($command->publishAtUtc)
                    : $existing->publishAtUtc(),
                unpublishAtUtc: $command->unpublishAtUtc !== null
                    ? new DateTimeImmutable($command->unpublishAtUtc)
                    : $existing->unpublishAtUtc(),
                discontinueAtUtc: $command->discontinueAtUtc !== null
                    ? new DateTimeImmutable($command->discontinueAtUtc)
                    : $existing->discontinueAtUtc(),
            );

            if ($command->isActive !== null) {
                $updated = $updated->withActive($command->isActive);
            }

            if ($command->updateClassification) {
                $updated = $updated->withClassification(
                    familyId: $command->familyPublicId !== null
                        ? $this->classification->findFamilyIdByPublicId($command->familyPublicId)
                        : null,
                    categoryId: $command->categoryPublicId !== null
                        ? $this->classification->findCategoryIdByPublicId($command->categoryPublicId)
                        : null,
                    seriesId: $command->seriesPublicId !== null
                        ? $this->classification->findSeriesIdByPublicId($command->seriesPublicId)
                        : null,
                );
            }

            $expectedRowVersion = RowVersionToken::fromBase64($command->expectedRowVersion);
            $actorUserId = $this->audit->actorUserId($actor);

            try {
                $saved = $this->products->save($updated, $actorUserId, $expectedRowVersion);
            } catch (ProductConcurrencyException $exception) {
                throw ProductConcurrencyConflict::fromDomainException($exception);
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: $saved->publicId(),
                entityTypeCode: 'product',
                entityId: $saved->id()?->toInt(),
                actionCode: 'updated',
                previousValues: ['productName' => $existing->productName()],
                newValues: ['productName' => $saved->productName()],
            );

            return self::toResult($saved);
        });
    }

    public static function toResult(Product $product): ProductMutationResult
    {
        $rowVersion = $product->rowVersion();

        return new ProductMutationResult(
            publicId: $product->publicId()->toString(),
            rowVersion: $rowVersion?->toBase64() ?? '',
            statusCode: $product->status()->toString(),
            createdAtUtc: null,
            updatedAtUtc: (new DateTimeImmutable())->format(DATE_ATOM),
        );
    }
}
