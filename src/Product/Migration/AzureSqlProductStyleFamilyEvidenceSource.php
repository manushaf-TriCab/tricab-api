<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Contract\ProductStyleFamilyEvidenceSource;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Read-only Family/Category/Series + product spec/media evidence.
 * Does not write classification, specifications, media, or blobs.
 */
final class AzureSqlProductStyleFamilyEvidenceSource implements ProductStyleFamilyEvidenceSource
{
    public function __construct(
        private readonly ProductAdminQueryRepository $products,
        private readonly ProductClassificationRepository $classification,
        private readonly ProductSpecificationRepository $specifications,
        private readonly ProductMediaRepository $media,
    ) {
    }

    public function load(array $productPublicIds): array
    {
        $out = [];
        foreach ($productPublicIds as $rawId) {
            if (!ProductPublicId::isValid($rawId)) {
                continue;
            }
            $id = ProductPublicId::fromString($rawId);
            $detail = $this->products->findDetailByPublicId($id);
            if ($detail === null) {
                continue;
            }
            $family = $detail->familyPublicId !== null
                ? $this->classification->findFamilyByPublicId($detail->familyPublicId)
                : null;
            $category = $detail->categoryPublicId !== null
                ? $this->classification->findCategoryByPublicId($detail->categoryPublicId)
                : null;
            $series = $detail->seriesPublicId !== null
                ? $this->classification->findSeriesByPublicId($detail->seriesPublicId)
                : null;
            $definitionIds = [];
            $fingerprints = [];
            foreach ($this->specifications->listProductValues($id) as $value) {
                $defId = (string) ($value['definitionPublicId'] ?? '');
                if ($defId === '') {
                    continue;
                }
                $definitionIds[] = $defId;
                $fingerprints[$defId] = json_encode([
                    $value['valueText'] ?? null,
                    $value['valueLongText'] ?? null,
                    $value['valueInteger'] ?? null,
                    $value['valueDecimal'] ?? null,
                    $value['valueBoolean'] ?? null,
                    $value['optionPublicId'] ?? null,
                    $value['optionPublicIds'] ?? [],
                ], JSON_THROW_ON_ERROR);
            }
            $mediaKeys = [];
            foreach ($this->media->listByProduct($id) as $asset) {
                $key = $asset->storagePath ?? $asset->originalFileName ?? $asset->title;
                if (is_string($key) && $key !== '') {
                    $mediaKeys[] = $key;
                }
            }
            $out[$rawId] = [
                'familyCode' => $family?->code,
                'familyName' => $family?->name,
                'categoryCode' => $category?->code,
                'categoryName' => $category?->name,
                'seriesCode' => $series?->code,
                'seriesName' => $series?->name,
                'specificationDefinitionIds' => array_values(array_unique($definitionIds)),
                'specificationFingerprints' => $fingerprints,
                'mediaKeys' => array_values(array_unique($mediaKeys)),
            ];
        }

        return $out;
    }
}
