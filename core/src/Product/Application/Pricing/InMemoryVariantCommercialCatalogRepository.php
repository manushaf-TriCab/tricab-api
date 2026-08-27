<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/** In-memory commercial catalogue for unit tests. */
final class InMemoryVariantCommercialCatalogRepository implements VariantCommercialCatalogRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    /**
     * @param array<string, mixed> $catalog
     */
    public function seed(string $variantPublicId, array $catalog): void
    {
        $this->rows[$variantPublicId] = $catalog;
    }

    /**
     * @param array<string, mixed> $base Legacy VariantBaseCostRepository row shape.
     */
    public function seedFromLegacyBase(string $variantPublicId, array $base, ?array $azureBase = null): void
    {
        $resolvedAzure = $azureBase;
        if ($resolvedAzure === null && isset($base['baseCost'])) {
            $resolvedAzure = [
                'baseCost' => (string) $base['baseCost'],
                'currencyCode' => (string) ($base['currencyCode'] ?? 'AUD'),
                'priceListCode' => (string) ($base['priceListCode'] ?? 'TCA-BASE'),
                'availability' => (string) ($base['availability'] ?? CustomerSellPriceStatus::AVAILABILITY_UNKNOWN),
            ];
        }

        $this->seed($variantPublicId, [
            'variantPublicId' => $variantPublicId,
            'variantCode' => (string) ($base['variantCode'] ?? ''),
            'sku' => isset($base['sku']) ? (string) $base['sku'] : null,
            'styleCode' => isset($base['styleCode']) ? (string) $base['styleCode'] : null,
            'uomCode' => (string) ($base['uomCode'] ?? 'M'),
            'uomSymbol' => (string) ($base['uomSymbol'] ?? 'm'),
            'legacyProductCode' => (string) ($base['variantCode'] ?? $base['sku'] ?? ''),
            'azureBase' => $resolvedAzure,
        ]);
    }

    public function findCommercialCatalog(string $variantPublicId, string $asOfDateUtc): ?array
    {
        unset($asOfDateUtc);

        return $this->rows[$variantPublicId] ?? null;
    }
}
