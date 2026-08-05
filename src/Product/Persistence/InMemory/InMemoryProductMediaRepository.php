<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * In-memory product media repository. {@see archive()} soft-deletes media metadata only.
 */
final class InMemoryProductMediaRepository implements ProductMediaRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $items = [];
        foreach ($this->store->media() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString() || ($row['archived'] ?? false)) {
                continue;
            }
            $items[] = $this->toSummary($row);
        }

        return $items;
    }

    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductMediaSummary {
        $row = [
            'publicId' => $this->store->newPublicId(),
            'productPublicId' => $productPublicId->toString(),
            'mediaCategoryCode' => $data['mediaCategoryCode'],
            'mediaTypeCode' => $data['mediaTypeCode'],
            'title' => $data['title'],
            'publicUrl' => $data['publicUrl'] ?? null,
            'altText' => $data['altText'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isPrimary' => $data['isPrimary'] ?? false,
            'archived' => false,
        ];
        $this->store->addMedia($row);

        if ($row['isPrimary']) {
            $this->setPrimary($productPublicId, $row['publicId'], $actorUserId);
        }

        return $this->toSummary($row);
    }

    public function setPrimary(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void {
        foreach ($this->store->media() as $index => $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString()) {
                continue;
            }
            $row['isPrimary'] = ($row['publicId'] ?? '') === $mediaPublicId;
            $this->store->updateMedia($productPublicId->toString(), static fn (): array => $row);
        }
    }

    public function reorder(
        ProductPublicId $productPublicId,
        array $orderedMediaPublicIds,
        UserId $actorUserId,
    ): void {
        foreach ($orderedMediaPublicIds as $index => $mediaPublicId) {
            foreach ($this->store->media() as $row) {
                if (
                    ($row['productPublicId'] ?? '') === $productPublicId->toString()
                    && ($row['publicId'] ?? '') === $mediaPublicId
                ) {
                    $row['displayOrder'] = $index + 1;
                    $this->store->addMedia($row);
                }
            }
        }
    }

    public function archive(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void {
        foreach ($this->store->media() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId->toString()
                && ($row['publicId'] ?? '') === $mediaPublicId
            ) {
                $row['archived'] = true;
                $this->store->addMedia($row);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSummary(array $row): ProductMediaSummary
    {
        return new ProductMediaSummary(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            mediaCategoryCode: AzureSqlValueMapper::stringValue($row['mediaCategoryCode'] ?? ''),
            mediaTypeCode: AzureSqlValueMapper::stringValue($row['mediaTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['title'] ?? ''),
            publicUrl: AzureSqlValueMapper::nullableString($row['publicUrl'] ?? null),
            altText: AzureSqlValueMapper::nullableString($row['altText'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            isPrimary: AzureSqlValueMapper::boolValue($row['isPrimary'] ?? false),
        );
    }
}
