<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\VariantLegacyPricingIdentityMapping;
use TriCab\Core\Product\Contract\VariantLegacyPricingIdentityRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

final class InMemoryVariantLegacyPricingIdentityRepository implements VariantLegacyPricingIdentityRepository
{
    /** @var array<string, array{publicId: string, variantPublicId: string, legacyPriceCode: string, isActive: bool}> */
    private array $byVariantPublicId = [];

    /** @var array<int, string> */
    private array $variantIdToPublicId = [];

    private int $nextPublicId = 1;

    public function registerVariantId(int $variantId, string $variantPublicId): void
    {
        $this->variantIdToPublicId[$variantId] = $variantPublicId;
    }

    public function findByVariantPublicId(ProductVariantPublicId $publicId): ?VariantLegacyPricingIdentityMapping
    {
        $record = $this->byVariantPublicId[$publicId->toString()] ?? null;
        if ($record === null || !$record['isActive']) {
            return null;
        }

        return $this->hydrate($record);
    }

    public function findByVariantId(ProductVariantId $variantId): ?VariantLegacyPricingIdentityMapping
    {
        $publicId = $this->variantIdToPublicId[$variantId->toInt()] ?? null;
        if ($publicId === null) {
            return null;
        }

        return $this->findByVariantPublicId(ProductVariantPublicId::fromString($publicId));
    }

    public function findByVariantIds(array $variantIds): array
    {
        $out = [];
        foreach ($variantIds as $variantId) {
            $mapping = $this->findByVariantId(ProductVariantId::fromInt((int) $variantId));
            if ($mapping !== null) {
                $out[(int) $variantId] = $mapping;
            }
        }

        return $out;
    }

    public function upsert(
        ProductVariantId $variantId,
        ProductVariantPublicId $variantPublicId,
        string $legacyPriceCode,
        UserId $actorUserId,
    ): VariantLegacyPricingIdentityMapping {
        unset($actorUserId);
        $this->variantIdToPublicId[$variantId->toInt()] = $variantPublicId->toString();
        $existing = $this->byVariantPublicId[$variantPublicId->toString()] ?? null;
        $record = [
            'publicId' => $existing['publicId'] ?? sprintf('00000000-0000-0000-0000-%012d', $this->nextPublicId++),
            'variantPublicId' => $variantPublicId->toString(),
            'legacyPriceCode' => strtoupper(trim($legacyPriceCode)),
            'isActive' => true,
        ];
        $this->byVariantPublicId[$variantPublicId->toString()] = $record;

        return $this->hydrate($record);
    }

    public function deactivate(ProductVariantId $variantId, UserId $actorUserId): void
    {
        unset($actorUserId);
        $publicId = $this->variantIdToPublicId[$variantId->toInt()] ?? null;
        if ($publicId !== null) {
            unset($this->byVariantPublicId[$publicId]);
        }
    }

    /**
     * @param array{publicId: string, variantPublicId: string, legacyPriceCode: string, isActive: bool} $record
     */
    private function hydrate(array $record): VariantLegacyPricingIdentityMapping
    {
        return new VariantLegacyPricingIdentityMapping(
            publicId: $record['publicId'],
            variantPublicId: $record['variantPublicId'],
            legacyPriceCode: $record['legacyPriceCode'],
            isActive: $record['isActive'],
        );
    }
}
