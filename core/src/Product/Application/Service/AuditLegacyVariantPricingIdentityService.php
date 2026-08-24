<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\Command\AuditLegacyVariantPricingIdentityCommand;
use TriCab\Core\Product\Application\Command\ResolveLegacyVariantPricingIdentityCommand;
use TriCab\Core\Product\Application\DTO\LegacyVariantPricingIdentityAuditSummary;
use TriCab\Core\Product\Application\DTO\LegacyVariantPricingIdentityResolution;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Contract\VariantLegacyPricingIdentityRepository;
use TriCab\Core\Product\Domain\ValueObject\LegacyPricingResolutionStatus;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class AuditLegacyVariantPricingIdentityService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
        private readonly ResolveLegacyVariantPricingIdentityService $resolver,
    ) {
    }

    /**
     * @return list<LegacyVariantPricingIdentityResolution>
     */
    public function listForProduct(string $productPublicId, string $priceListPublicId): array
    {
        $matrix = $this->pricing->listMatrixByProduct(ProductPublicId::fromString($productPublicId));
        $out = [];
        foreach ($matrix as $row) {
            $out[] = $this->resolver->execute(new ResolveLegacyVariantPricingIdentityCommand(
                $row->variantPublicId,
                $priceListPublicId,
            ));
        }

        return $out;
    }

    public function audit(AuditLegacyVariantPricingIdentityCommand $command): LegacyVariantPricingIdentityAuditSummary
    {
        $started = microtime(true);

        if ($command->productPublicId === null || $command->productPublicId === '') {
            throw new \InvalidArgumentException('productPublicId is required for audit.');
        }
        if ($command->priceListPublicId === null || $command->priceListPublicId === '') {
            throw new \InvalidArgumentException('priceListPublicId is required for audit.');
        }

        $matrix = $this->pricing->listMatrixByProduct(ProductPublicId::fromString($command->productPublicId));
        $slice = array_slice($matrix, $command->offset, max(1, min(500, $command->limit)));

        $explicit = 0;
        $exact = 0;
        $mask = 0;
        $unresolved = 0;
        $ambiguous = 0;
        $invalidExplicit = 0;
        $zeroCost = 0;
        $revisionAmbiguous = 0;
        $dbco = null;

        foreach ($slice as $row) {
            $resolution = $this->resolver->execute(new ResolveLegacyVariantPricingIdentityCommand(
                $row->variantPublicId,
                $command->priceListPublicId,
            ));
            $dbco ??= $resolution->legacyDbco;

            match ($resolution->resolutionStatus) {
                LegacyPricingResolutionStatus::EXPLICIT => ++$explicit,
                LegacyPricingResolutionStatus::EXACT_CODE => ++$exact,
                LegacyPricingResolutionStatus::MASK => ++$mask,
                LegacyPricingResolutionStatus::UNRESOLVED => ++$unresolved,
                LegacyPricingResolutionStatus::AMBIGUOUS => ++$ambiguous,
                LegacyPricingResolutionStatus::EXPLICIT_MAPPING_INVALID => ++$invalidExplicit,
                default => null,
            };

            if ($resolution->priceDataStatus === 'ZERO_COST') {
                ++$zeroCost;
            }
            if ($resolution->priceDataStatus === 'PRICE_REVISION_AMBIGUOUS') {
                ++$revisionAmbiguous;
            }
        }

        return new LegacyVariantPricingIdentityAuditSummary(
            variantsScanned: count($slice),
            explicitCount: $explicit,
            exactCount: $exact,
            maskCount: $mask,
            unresolvedCount: $unresolved,
            ambiguousCount: $ambiguous,
            invalidExplicitCount: $invalidExplicit,
            zeroCostCount: $zeroCost,
            priceRevisionAmbiguousCount: $revisionAmbiguous,
            systemDefaultUomCount: 0,
            noPriceListContextCount: 0,
            byDbco: $dbco !== null ? [(string) $dbco => count($slice)] : [],
            legacyQueryCount: count($slice),
            executionSeconds: round(microtime(true) - $started, 3),
            warnings: [],
        );
    }
}
