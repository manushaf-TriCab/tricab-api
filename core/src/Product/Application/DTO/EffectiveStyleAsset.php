<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Style-owned or inherited media/document/360/3D/AR asset.
 *
 * @phpstan-type AssetRow array<string, mixed>
 */
final readonly class EffectiveStyleAsset
{
    /**
     * @param AssetRow $asset
     */
    public function __construct(
        public string $resourceType,
        public string $title,
        public ?string $roleCode,
        public ?string $storagePath,
        public ?string $storageContainer,
        public ?string $publicUrl,
        public int $displayOrder,
        public bool $isPrimary,
        public array $asset,
        public EffectiveResourceProvenance $provenance,
    ) {
    }
}
