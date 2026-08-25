<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\EffectiveResourceProvenance;
use TriCab\Core\Product\Application\DTO\EffectiveSpecificationValue;
use TriCab\Core\Product\Application\DTO\EffectiveStyleAsset;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\StyleInheritanceMode;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;

/**
 * Resolves effective specifications and assets with deterministic precedence:
 * Variant → Product → current style → parent → higher ancestors.
 *
 * Closest applicable value wins. Inherited rows are not copied.
 */
final class ResolveEffectiveStyleResourcesService
{
    private const EXCLUSIVE_ROLES = ['primary', 'hero', 'thumbnail'];

    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ProductSpecificationRepository $specifications,
    ) {
    }

    /**
     * @return list<EffectiveSpecificationValue>
     */
    public function specificationsForStyle(string $stylePublicId): array
    {
        $chain = $this->styleChain($stylePublicId);

        return array_values($this->resolveStyleSpecifications($chain));
    }

    /**
     * @return list<EffectiveSpecificationValue>
     */
    public function specificationsForProduct(
        string $productPublicId,
        ?string $variantPublicId = null,
    ): array {
        $assignment = $this->styles->findAssignmentByProductPublicId($productPublicId);
        $merged = [];
        if ($assignment !== null) {
            $merged = $this->resolveStyleSpecifications($this->styleChain($assignment->stylePublicId));
        }

        $definitions = $this->definitionIndex();
        foreach ($this->specifications->listProductValues(ProductPublicId::fromString($productPublicId)) as $value) {
            $item = $this->toEffectiveSpec(
                $value,
                $definitions,
                new EffectiveResourceProvenance(
                    sourceType: 'PRODUCT',
                    sourceStylePublicId: $assignment?->stylePublicId,
                    sourceStyleCode: $assignment?->styleCode,
                    sourceLevel: 0,
                    inherited: false,
                    label: 'Product override',
                ),
            );
            if ($item !== null) {
                $merged[$item->definitionPublicId] = $item;
            }
        }

        if ($variantPublicId !== null && trim($variantPublicId) !== '') {
            foreach ($this->specifications->listVariantValues(ProductVariantPublicId::fromString($variantPublicId)) as $value) {
                $item = $this->toEffectiveSpec(
                    $value,
                    $definitions,
                    new EffectiveResourceProvenance(
                        sourceType: 'VARIANT',
                        sourceStylePublicId: $assignment?->stylePublicId,
                        sourceStyleCode: $assignment?->styleCode,
                        sourceLevel: 0,
                        inherited: false,
                        label: 'Variant override',
                    ),
                );
                if ($item !== null) {
                    $merged[$item->definitionPublicId] = $item;
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @return list<EffectiveStyleAsset>
     */
    public function assetsForStyle(string $stylePublicId, StyleResourceType $type): array
    {
        return $this->resolveStyleAssets($this->styleChain($stylePublicId), $type);
    }

    /**
     * @return list<EffectiveStyleAsset>
     */
    public function assetsForProduct(string $productPublicId, StyleResourceType $type): array
    {
        $assignment = $this->styles->findAssignmentByProductPublicId($productPublicId);
        if ($assignment === null) {
            return [];
        }

        return $this->resolveStyleAssets($this->styleChain($assignment->stylePublicId), $type);
    }

    /**
     * @return array{inherited: int, styleOverrides: int, productOverrides: int, variantOverrides: int, local: int}
     */
    public function specificationSummary(array $items): array
    {
        $summary = [
            'inherited' => 0,
            'styleOverrides' => 0,
            'productOverrides' => 0,
            'variantOverrides' => 0,
            'local' => 0,
        ];
        foreach ($items as $item) {
            if (!$item instanceof EffectiveSpecificationValue) {
                continue;
            }
            $p = $item->provenance;
            if ($p->sourceType === 'PRODUCT') {
                $summary['productOverrides']++;
            } elseif ($p->sourceType === 'VARIANT') {
                $summary['variantOverrides']++;
            } elseif ($p->inherited) {
                $summary['inherited']++;
            } elseif ($p->sourceLevel === 0 && str_contains(strtolower($p->label), 'override')) {
                $summary['styleOverrides']++;
            } else {
                $summary['local']++;
            }
        }

        return $summary;
    }

    /**
     * @return list<ProductStyleRecord> current first, then parent toward root
     */
    private function styleChain(string $stylePublicId): array
    {
        $current = $this->styles->findByPublicId($stylePublicId, true);
        if ($current === null) {
            return [];
        }
        $ancestors = $this->styles->listAncestors($stylePublicId);

        return array_merge([$current], $ancestors);
    }

    /**
     * @param list<ProductStyleRecord> $chainCurrentFirst
     * @return array<string, EffectiveSpecificationValue>
     */
    private function resolveStyleSpecifications(array $chainCurrentFirst): array
    {
        if ($chainCurrentFirst === []) {
            return [];
        }
        $rootFirst = array_reverse($chainCurrentFirst);
        $ids = array_map(static fn (ProductStyleRecord $s): string => $s->publicId, $rootFirst);
        $byStyle = $this->styles->listSpecificationValuesForStyles($ids);
        $definitions = $this->definitionIndex();
        $currentPublicId = $chainCurrentFirst[0]->publicId;
        $effective = [];

        foreach ($rootFirst as $index => $style) {
            $level = count($rootFirst) - 1 - $index;
            $mode = StyleInheritanceMode::fromString($style->inheritance->specifications);
            $own = $byStyle[$style->publicId] ?? [];
            if ($mode->toString() === StyleInheritanceMode::LOCAL || $style->parentPublicId === null) {
                $effective = [];
                foreach ($own as $value) {
                    $item = $this->toEffectiveSpec(
                        $value,
                        $definitions,
                        $this->styleProvenance($style, $level, $currentPublicId, false),
                    );
                    if ($item !== null) {
                        $effective[$item->definitionPublicId] = $item;
                    }
                }
                continue;
            }
            if ($mode->toString() === StyleInheritanceMode::INHERIT) {
                continue;
            }
            foreach ($own as $value) {
                $item = $this->toEffectiveSpec(
                    $value,
                    $definitions,
                    $this->styleProvenance($style, $level, $currentPublicId, true),
                );
                if ($item !== null) {
                    $effective[$item->definitionPublicId] = $item;
                }
            }
        }

        return $effective;
    }

    /**
     * @param list<ProductStyleRecord> $chainCurrentFirst
     * @return list<EffectiveStyleAsset>
     */
    private function resolveStyleAssets(array $chainCurrentFirst, StyleResourceType $type): array
    {
        if ($chainCurrentFirst === []) {
            return [];
        }
        $rootFirst = array_reverse($chainCurrentFirst);
        $ids = array_map(static fn (ProductStyleRecord $s): string => $s->publicId, $rootFirst);
        $byStyle = $this->styles->listAssetsForStyles($ids, $type);
        $currentPublicId = $chainCurrentFirst[0]->publicId;
        $effective = [];

        foreach ($rootFirst as $index => $style) {
            $level = count($rootFirst) - 1 - $index;
            $mode = StyleInheritanceMode::fromString($style->inheritance->modeFor($type));
            $own = $byStyle[$style->publicId] ?? [];
            $tagged = [];
            foreach ($own as $asset) {
                $tagged[] = $this->toEffectiveAsset(
                    $asset,
                    $type,
                    $this->styleProvenance($style, $level, $currentPublicId, $mode->toString() !== StyleInheritanceMode::LOCAL),
                );
            }
            if ($mode->toString() === StyleInheritanceMode::LOCAL || $style->parentPublicId === null) {
                $effective = $tagged;
                continue;
            }
            if ($mode->toString() === StyleInheritanceMode::INHERIT) {
                continue;
            }
            $effective = $this->mergeAssets($effective, $tagged);
        }

        usort(
            $effective,
            static fn (EffectiveStyleAsset $a, EffectiveStyleAsset $b): int =>
                [$a->displayOrder, $a->title] <=> [$b->displayOrder, $b->title],
        );

        return $effective;
    }

    /**
     * @param list<EffectiveStyleAsset> $base
     * @param list<EffectiveStyleAsset> $additions
     * @return list<EffectiveStyleAsset>
     */
    private function mergeAssets(array $base, array $additions): array
    {
        $out = $base;
        foreach ($additions as $asset) {
            $replaced = false;
            $role = strtolower((string) $asset->roleCode);
            if ($role !== '' && in_array($role, self::EXCLUSIVE_ROLES, true)) {
                foreach ($out as $i => $existing) {
                    if (strtolower((string) $existing->roleCode) === $role) {
                        $out[$i] = $asset;
                        $replaced = true;
                        break;
                    }
                }
            }
            if ($replaced) {
                continue;
            }
            $path = $asset->storagePath ?? '';
            $duplicate = false;
            if ($path !== '') {
                foreach ($out as $existing) {
                    if (($existing->storagePath ?? '') === $path) {
                        $duplicate = true;
                        break;
                    }
                }
            }
            if (!$duplicate) {
                $out[] = $asset;
            }
        }

        return $out;
    }

    private function styleProvenance(
        ProductStyleRecord $style,
        int $level,
        string $currentPublicId,
        bool $mayBeOverride,
    ): EffectiveResourceProvenance {
        $isCurrent = strcasecmp($style->publicId, $currentPublicId) === 0;
        $inherited = !$isCurrent;
        if ($isCurrent && $mayBeOverride && $style->parentPublicId !== null) {
            $label = $style->styleCode . ' override';
        } elseif ($inherited) {
            $label = 'Inherited from ' . $style->styleCode;
        } else {
            $label = 'Local';
        }

        return new EffectiveResourceProvenance(
            sourceType: 'STYLE',
            sourceStylePublicId: $style->publicId,
            sourceStyleCode: $style->styleCode,
            sourceLevel: $level,
            inherited: $inherited,
            label: $label,
        );
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, array<string, mixed>> $definitions
     */
    private function toEffectiveSpec(
        array $value,
        array $definitions,
        EffectiveResourceProvenance $provenance,
    ): ?EffectiveSpecificationValue {
        $definitionPublicId = (string) ($value['definitionPublicId'] ?? '');
        if ($definitionPublicId === '') {
            return null;
        }
        $definition = $definitions[$definitionPublicId] ?? null;
        $code = is_array($definition) ? (string) ($definition['definitionCode'] ?? $definition['code'] ?? $definitionPublicId) : $definitionPublicId;
        $name = is_array($definition) ? (string) ($definition['definitionName'] ?? $definition['name'] ?? $code) : $code;

        return new EffectiveSpecificationValue(
            definitionPublicId: $definitionPublicId,
            definitionCode: $code,
            definitionName: $name,
            displayValue: $this->displayValue($value, is_array($definition) ? $definition : []),
            value: [
                'definitionPublicId' => $definitionPublicId,
                'valueText' => isset($value['valueText']) && is_string($value['valueText']) ? $value['valueText'] : null,
                'valueLongText' => isset($value['valueLongText']) && is_string($value['valueLongText']) ? $value['valueLongText'] : null,
                'valueInteger' => isset($value['valueInteger']) && is_int($value['valueInteger']) ? $value['valueInteger'] : null,
                'valueDecimal' => isset($value['valueDecimal']) ? (is_string($value['valueDecimal']) ? $value['valueDecimal'] : (is_numeric($value['valueDecimal']) ? (string) $value['valueDecimal'] : null)) : null,
                'valueBoolean' => isset($value['valueBoolean']) && is_bool($value['valueBoolean']) ? $value['valueBoolean'] : null,
                'valueDate' => isset($value['valueDate']) && is_string($value['valueDate']) ? $value['valueDate'] : null,
                'valueUrl' => isset($value['valueUrl']) && is_string($value['valueUrl']) ? $value['valueUrl'] : null,
                'valueMinDecimal' => isset($value['valueMinDecimal']) && is_string($value['valueMinDecimal']) ? $value['valueMinDecimal'] : null,
                'valueMaxDecimal' => isset($value['valueMaxDecimal']) && is_string($value['valueMaxDecimal']) ? $value['valueMaxDecimal'] : null,
                'optionPublicId' => isset($value['optionPublicId']) && is_string($value['optionPublicId']) ? $value['optionPublicId'] : null,
                'optionPublicIds' => isset($value['optionPublicIds']) && is_array($value['optionPublicIds'])
                    ? array_values(array_filter($value['optionPublicIds'], 'is_string'))
                    : [],
            ],
            provenance: $provenance,
        );
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function toEffectiveAsset(
        array $asset,
        StyleResourceType $type,
        EffectiveResourceProvenance $provenance,
    ): EffectiveStyleAsset {
        return new EffectiveStyleAsset(
            resourceType: $type->toString(),
            title: is_string($asset['title'] ?? null) ? $asset['title'] : 'Asset',
            roleCode: isset($asset['roleCode']) && is_string($asset['roleCode']) ? $asset['roleCode'] : null,
            storagePath: isset($asset['storagePath']) && is_string($asset['storagePath']) ? $asset['storagePath'] : null,
            storageContainer: isset($asset['storageContainer']) && is_string($asset['storageContainer']) ? $asset['storageContainer'] : null,
            publicUrl: isset($asset['publicUrl']) && is_string($asset['publicUrl']) ? $asset['publicUrl'] : null,
            displayOrder: is_int($asset['displayOrder'] ?? null) ? $asset['displayOrder'] : 0,
            isPrimary: (bool) ($asset['isPrimary'] ?? false),
            asset: $asset,
            provenance: $provenance,
        );
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $definition
     */
    private function displayValue(array $value, array $definition): ?string
    {
        if (isset($value['valueText']) && is_string($value['valueText']) && $value['valueText'] !== '') {
            return $value['valueText'];
        }
        if (isset($value['valueLongText']) && is_string($value['valueLongText']) && $value['valueLongText'] !== '') {
            return $value['valueLongText'];
        }
        if (isset($value['valueDecimal']) && (is_string($value['valueDecimal']) || is_numeric($value['valueDecimal']))) {
            return (string) $value['valueDecimal'];
        }
        if (isset($value['valueInteger']) && is_int($value['valueInteger'])) {
            return (string) $value['valueInteger'];
        }
        if (isset($value['valueBoolean']) && is_bool($value['valueBoolean'])) {
            return $value['valueBoolean'] ? 'Yes' : 'No';
        }
        if (isset($value['valueDate']) && is_string($value['valueDate'])) {
            return $value['valueDate'];
        }
        if (isset($value['valueUrl']) && is_string($value['valueUrl'])) {
            return $value['valueUrl'];
        }
        $optionPublicId = $value['optionPublicId'] ?? null;
        if (is_string($optionPublicId) && isset($definition['options']) && is_array($definition['options'])) {
            foreach ($definition['options'] as $option) {
                if (is_array($option) && ($option['publicId'] ?? null) === $optionPublicId) {
                    return isset($option['optionLabel']) ? (string) $option['optionLabel'] : (string) ($option['optionCode'] ?? $optionPublicId);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitionIndex(): array
    {
        $index = [];
        foreach ($this->specifications->listDefinitions(true) as $definition) {
            $index[$definition['publicId']] = $definition;
        }

        return $index;
    }
}
