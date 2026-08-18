<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Product\Application\Command\CreateProductCommand;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\InternalProductCode;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class CreateProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductClassificationRepository $classification,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    public function execute(ActorContext $actor, CreateProductCommand $command): ProductMutationResult
    {
        $this->classification->assertHierarchyValid(
            $command->familyPublicId,
            $command->categoryPublicId,
            $command->seriesPublicId,
        );

        return $this->transaction->run(function () use ($actor, $command): ProductMutationResult {
            $publicId = ProductPublicId::fromString($this->uuids->generate()->toString());

            $product = new Product(
                id: null,
                publicId: $publicId,
                productName: $command->productName,
                status: ProductStatusCode::fromString($command->statusCode),
                lifecycleStatus: ProductLifecycleStatusCode::fromString($command->lifecycleStatusCode),
                familyId: $command->familyPublicId !== null
                    ? $this->classification->findFamilyIdByPublicId($command->familyPublicId)
                    : null,
                categoryId: $command->categoryPublicId !== null
                    ? $this->classification->findCategoryIdByPublicId($command->categoryPublicId)
                    : null,
                seriesId: $command->seriesPublicId !== null
                    ? $this->classification->findSeriesIdByPublicId($command->seriesPublicId)
                    : null,
                internalName: $command->internalName,
                internalCode: $command->internalCode !== null
                    ? InternalProductCode::fromString($command->internalCode)
                    : null,
                shortDescription: $command->shortDescription,
                fullDescription: $command->fullDescription,
                legacyProductId: $command->legacyProductId,
                erpReference: $command->erpReference,
                isFeatured: $command->isFeatured,
                publishAtUtc: self::toDateTime($command->publishAtUtc),
                unpublishAtUtc: self::toDateTime($command->unpublishAtUtc),
                discontinueAtUtc: self::toDateTime($command->discontinueAtUtc),
                isActive: $command->isActive,
            );

            $actorUserId = $this->audit->actorUserId($actor);
            $saved = $this->products->save($product, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $saved->publicId(),
                entityTypeCode: 'product',
                entityId: $saved->id()?->toInt(),
                actionCode: 'created',
                newValues: [
                    'productName' => $saved->productName(),
                    'statusCode' => $saved->status()->toString(),
                    'lifecycleStatusCode' => $saved->lifecycleStatus()->toString(),
                ],
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

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }
}
