<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Application\Exception\ProductVariantNotFound;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\Service\Cable3DSpecificationMapping;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * Builds a focused 3D specification-source DTO from Product/Variant specs.
 */
final class GetProduct3DSpecificationSourceService
{
    public function __construct(
        private readonly ProductAdminQueryRepository $products,
        private readonly ProductSpecificationRepository $specifications,
        private readonly Cable3DSpecificationMapping $mapping = new Cable3DSpecificationMapping(),
    ) {
    }

    /**
     * @return array{
     *   productPublicId: string,
     *   productVariantPublicId: ?string,
     *   detailLevel: string,
     *   canGenerate: bool,
     *   sourceHash: string,
     *   values: array<string, array{value: float|int|string, unit: ?string, source: string, definitionCode: string, displayValue?: ?string}>,
     *   missing: list<string>,
     *   resolved: array<string, string>
     * }
     */
    public function execute(string $productPublicId, ?string $variantPublicId = null): array
    {
        $productId = ProductPublicId::fromString($productPublicId);
        $detail = $this->products->findDetailByPublicId($productId);
        if ($detail === null) {
            throw ProductNotFound::forPublicId($productPublicId);
        }

        $definitions = $this->specifications->listDefinitions(true);
        $defsByPublicId = [];
        foreach ($definitions as $definition) {
            $defsByPublicId[$definition['publicId']] = $definition;
        }

        $merged = [];
        foreach ($this->specifications->listProductValues($productId) as $value) {
            $mapped = $this->mapValueRow($value, $defsByPublicId, 'product');
            if ($mapped !== null) {
                $merged[strtoupper($mapped['code'])] = $mapped;
            }
        }

        $resolvedVariantPublicId = null;
        if ($variantPublicId !== null && trim($variantPublicId) !== '') {
            $variantId = ProductVariantPublicId::fromString($variantPublicId);
            $variants = $this->products->listVariantSummariesByProduct($productId, true, false);
            $belongs = false;
            foreach ($variants as $variant) {
                if (strcasecmp($variant->publicId, $variantPublicId) === 0) {
                    $belongs = true;
                    break;
                }
            }
            if (!$belongs) {
                throw ProductVariantNotFound::forPublicId($variantPublicId);
            }
            $resolvedVariantPublicId = $variantId->toString();
            foreach ($this->specifications->listVariantValues($variantId) as $value) {
                $mapped = $this->mapValueRow($value, $defsByPublicId, 'variant');
                if ($mapped !== null) {
                    $merged[strtoupper($mapped['code'])] = $mapped;
                }
            }
        }

        $resolved = $this->mapping->resolve(array_values($merged));

        return [
            'productPublicId' => $productId->toString(),
            'productVariantPublicId' => $resolvedVariantPublicId,
            'detailLevel' => $resolved['detailLevel'],
            'canGenerate' => $resolved['canGenerate'],
            'sourceHash' => $resolved['sourceHash'],
            'values' => $resolved['values'],
            'missing' => $resolved['missing'],
            'resolved' => $resolved['resolved'],
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, array<string, mixed>> $defsByPublicId
     * @return array{code: string, value: float|int|string|null, displayValue: ?string, unit: ?string, source: string}|null
     */
    private function mapValueRow(array $value, array $defsByPublicId, string $source): ?array
    {
        $definitionPublicId = (string) ($value['definitionPublicId'] ?? '');
        $definition = $defsByPublicId[$definitionPublicId] ?? null;
        if ($definition === null) {
            return null;
        }
        $code = strtoupper(trim((string) ($definition['definitionCode'] ?? '')));
        if ($code === '') {
            return null;
        }

        $raw = $value['valueDecimal']
            ?? $value['valueInteger']
            ?? $value['valueText']
            ?? $value['valueLongText']
            ?? null;

        $optionLabel = null;
        $optionPublicId = $value['optionPublicId'] ?? null;
        if (is_string($optionPublicId) && $optionPublicId !== '' && isset($definition['options']) && is_array($definition['options'])) {
            foreach ($definition['options'] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                if (($option['publicId'] ?? null) === $optionPublicId) {
                    $optionLabel = isset($option['optionLabel']) ? (string) $option['optionLabel'] : null;
                    break;
                }
            }
        }
        if ($raw === null && $optionLabel !== null) {
            $raw = $optionLabel;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        return [
            'code' => $code,
            'value' => is_numeric($raw) ? 0 + $raw : $raw,
            'displayValue' => $optionLabel ?? (is_scalar($raw) ? (string) $raw : null),
            'unit' => isset($definition['unit']) ? (is_string($definition['unit']) ? $definition['unit'] : null) : null,
            'source' => $source,
        ];
    }
}
