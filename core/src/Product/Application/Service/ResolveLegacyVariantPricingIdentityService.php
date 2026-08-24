<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\Command\ResolveLegacyVariantPricingIdentityCommand;
use TriCab\Core\Product\Application\DTO\LegacyPricingCodeInspection;
use TriCab\Core\Product\Application\DTO\LegacyVariantPricingIdentityResolution;
use TriCab\Core\Product\Application\Exception\PriceListNotFound;
use TriCab\Core\Product\Contract\PriceListRepository;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Contract\VariantLegacyPricingIdentityRepository;
use TriCab\Core\Product\Domain\Service\LegacyPricingMaskGenerator;
use TriCab\Core\Product\Domain\ValueObject\LegacyPricingPriceDataStatus;
use TriCab\Core\Product\Domain\ValueObject\LegacyPricingResolutionSource;
use TriCab\Core\Product\Domain\ValueObject\LegacyPricingResolutionStatus;
use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;

final class ResolveLegacyVariantPricingIdentityService
{
    public function __construct(
        private readonly ProductVariantRepository $variants,
        private readonly VariantLegacyPricingIdentityRepository $identityMappings,
        private readonly LegacyTcRepository $legacyTc,
        private readonly PriceListRepository $priceLists,
        private readonly LegacyPricingMaskGenerator $maskGenerator,
    ) {
    }

    public function execute(ResolveLegacyVariantPricingIdentityCommand $command): LegacyVariantPricingIdentityResolution
    {
        $variant = $this->variants->findByPublicId(ProductVariantPublicId::fromString($command->variantPublicId));
        if ($variant === null) {
            throw new \InvalidArgumentException('Variant not found.');
        }

        $variantCode = $variant->variantCode()?->toString();
        $dbco = $this->resolveDbco($command->priceListPublicId);
        $explicit = $this->identityMappings->findByVariantPublicId($variant->publicId());

        if ($explicit !== null && $explicit->isActive) {
            return $this->resolveExplicit($command, $variantCode, $dbco, $explicit->legacyPriceCode);
        }

        return $this->resolveAutomatic($command, $variantCode, $dbco);
    }

    private function resolveExplicit(
        ResolveLegacyVariantPricingIdentityCommand $command,
        ?string $variantCode,
        int $dbco,
        string $explicitCode,
    ): LegacyVariantPricingIdentityResolution {
        $normalizedCode = strtoupper(trim($explicitCode));
        $inspection = $this->inspectCode($normalizedCode, $dbco);

        if (!$inspection->exists) {
            return new LegacyVariantPricingIdentityResolution(
                variantPublicId: $command->variantPublicId,
                variantCode: $variantCode,
                priceListPublicId: $command->priceListPublicId,
                legacyDbco: $dbco,
                resolutionStatus: LegacyPricingResolutionStatus::EXPLICIT_MAPPING_INVALID,
                legacyPriceCode: $normalizedCode,
                resolutionSource: LegacyPricingResolutionSource::INVALID,
                explicitMappingCode: $normalizedCode,
                exactCandidateCode: $variantCode,
                maskCandidates: [],
                legacyMatch: $inspection,
                priceDataStatus: LegacyPricingPriceDataStatus::NOT_FOUND,
                warnings: ['Explicit mapping code not found in legacy tc.price for selected dbco.'],
            );
        }

        return new LegacyVariantPricingIdentityResolution(
            variantPublicId: $command->variantPublicId,
            variantCode: $variantCode,
            priceListPublicId: $command->priceListPublicId,
            legacyDbco: $dbco,
            resolutionStatus: LegacyPricingResolutionStatus::EXPLICIT,
            legacyPriceCode: $normalizedCode,
            resolutionSource: LegacyPricingResolutionSource::APPROVED,
            explicitMappingCode: $normalizedCode,
            exactCandidateCode: $variantCode,
            maskCandidates: [],
            legacyMatch: $inspection,
            priceDataStatus: $inspection->priceDataStatus,
            warnings: $this->priceDataWarnings($inspection),
        );
    }

    private function resolveAutomatic(
        ResolveLegacyVariantPricingIdentityCommand $command,
        ?string $variantCode,
        int $dbco,
    ): LegacyVariantPricingIdentityResolution {
        $normalizedVariantCode = $variantCode !== null ? strtoupper(trim($variantCode)) : '';
        $exactCandidate = $normalizedVariantCode !== '' ? $normalizedVariantCode : null;
        $maskCandidates = $exactCandidate !== null ? $this->maskGenerator->generateCandidates($exactCandidate) : [];

        $codesToInspect = [];
        if ($exactCandidate !== null) {
            $codesToInspect[] = $exactCandidate;
        }
        foreach ($maskCandidates as $maskCandidate) {
            if (!in_array($maskCandidate, $codesToInspect, true)) {
                $codesToInspect[] = $maskCandidate;
            }
        }

        $inspections = $codesToInspect === [] ? [] : $this->legacyTc->inspectPricingCodes($codesToInspect, $dbco);

        $exactInspection = $exactCandidate !== null
            ? $this->hydrateInspection($inspections[$exactCandidate] ?? null)
            : null;

        if ($exactInspection !== null && $exactInspection->exists) {
            return new LegacyVariantPricingIdentityResolution(
                variantPublicId: $command->variantPublicId,
                variantCode: $variantCode,
                priceListPublicId: $command->priceListPublicId,
                legacyDbco: $dbco,
                resolutionStatus: LegacyPricingResolutionStatus::EXACT_CODE,
                legacyPriceCode: $exactCandidate,
                resolutionSource: LegacyPricingResolutionSource::EXACT,
                explicitMappingCode: null,
                exactCandidateCode: $exactCandidate,
                maskCandidates: $maskCandidates,
                legacyMatch: $exactInspection,
                priceDataStatus: $exactInspection->priceDataStatus,
                warnings: $this->priceDataWarnings($exactInspection),
            );
        }

        $validMasks = [];
        foreach ($maskCandidates as $maskCandidate) {
            $inspection = $this->hydrateInspection($inspections[$maskCandidate] ?? null);
            if ($inspection !== null && $inspection->exists) {
                $validMasks[$maskCandidate] = $inspection;
            }
        }

        if (count($validMasks) === 1) {
            $maskCode = array_key_first($validMasks);
            $inspection = $validMasks[$maskCode];

            return new LegacyVariantPricingIdentityResolution(
                variantPublicId: $command->variantPublicId,
                variantCode: $variantCode,
                priceListPublicId: $command->priceListPublicId,
                legacyDbco: $dbco,
                resolutionStatus: LegacyPricingResolutionStatus::MASK,
                legacyPriceCode: $maskCode,
                resolutionSource: LegacyPricingResolutionSource::MASK,
                explicitMappingCode: null,
                exactCandidateCode: $exactCandidate,
                maskCandidates: $maskCandidates,
                legacyMatch: $inspection,
                priceDataStatus: $inspection->priceDataStatus,
                warnings: $this->priceDataWarnings($inspection),
            );
        }

        if (count($validMasks) > 1) {
            return new LegacyVariantPricingIdentityResolution(
                variantPublicId: $command->variantPublicId,
                variantCode: $variantCode,
                priceListPublicId: $command->priceListPublicId,
                legacyDbco: $dbco,
                resolutionStatus: LegacyPricingResolutionStatus::AMBIGUOUS,
                legacyPriceCode: null,
                resolutionSource: LegacyPricingResolutionSource::AMBIGUOUS,
                explicitMappingCode: null,
                exactCandidateCode: $exactCandidate,
                maskCandidates: array_keys($validMasks),
                legacyMatch: null,
                priceDataStatus: null,
                warnings: ['Multiple valid mask candidates found in legacy tc.price.'],
            );
        }

        return new LegacyVariantPricingIdentityResolution(
            variantPublicId: $command->variantPublicId,
            variantCode: $variantCode,
            priceListPublicId: $command->priceListPublicId,
            legacyDbco: $dbco,
            resolutionStatus: LegacyPricingResolutionStatus::UNRESOLVED,
            legacyPriceCode: null,
            resolutionSource: LegacyPricingResolutionSource::UNRESOLVED,
            explicitMappingCode: null,
            exactCandidateCode: $exactCandidate,
            maskCandidates: $maskCandidates,
            legacyMatch: null,
            priceDataStatus: LegacyPricingPriceDataStatus::NOT_FOUND,
            warnings: $exactCandidate === null
                ? ['Variant has no VariantCode for legacy pricing identity resolution.']
                : ['No exact or mask legacy pricing code found for selected dbco.'],
        );
    }

    private function resolveDbco(string $priceListPublicId): int
    {
        $priceList = $this->priceLists->getByPublicId($priceListPublicId);
        if ($priceList === null) {
            throw PriceListNotFound::forPublicId($priceListPublicId);
        }
        if (
            $priceList->legacySourceCode !== PriceListLegacyContext::SYSTEM_CODE
            || $priceList->legacyContextType !== PriceListLegacyContext::CONTEXT_TYPE
            || $priceList->legacyContextCode === null
            || !ctype_digit($priceList->legacyContextCode)
        ) {
            throw new \InvalidArgumentException('Selected price list does not have a legacy tc dbco mapping.');
        }

        return (int) $priceList->legacyContextCode;
    }

    private function inspectCode(string $code, int $dbco): LegacyPricingCodeInspection
    {
        $row = $this->legacyTc->inspectPricingCode($code, $dbco);

        return $this->hydrateInspection($row);
    }

    /**
     * @param array{
     *   exists: bool,
     *   revisionCount: int,
     *   latestDc: ?string,
     *   latestCost: ?string,
     *   zeroCost: bool,
     *   duplicateLatestDc: bool,
     * }|null $row
     */
    private function hydrateInspection(?array $row): LegacyPricingCodeInspection
    {
        if ($row === null) {
            return LegacyPricingCodeInspection::fromLegacyRow([
                'exists' => false,
                'revisionCount' => 0,
                'latestDc' => null,
                'latestCost' => null,
                'zeroCost' => false,
                'duplicateLatestDc' => false,
            ]);
        }

        return LegacyPricingCodeInspection::fromLegacyRow($row);
    }

    /**
     * @return list<string>
     */
    private function priceDataWarnings(LegacyPricingCodeInspection $inspection): array
    {
        $warnings = [];
        if ($inspection->zeroCost) {
            $warnings[] = 'Latest legacy cost is zero — identity valid, import flagged for review.';
        }
        if ($inspection->duplicateLatestDc) {
            $warnings[] = 'Multiple materially different costs at latest dc — price revision ambiguous.';
        }

        return $warnings;
    }
}
