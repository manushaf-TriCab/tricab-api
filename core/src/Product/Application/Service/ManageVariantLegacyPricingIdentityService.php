<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\SetVariantLegacyPricingIdentityCommand;
use TriCab\Core\Product\Application\Command\VerifyVariantLegacyPricingIdentityCommand;
use TriCab\Core\Product\Application\DTO\LegacyPricingCodeInspection;
use TriCab\Core\Product\Application\DTO\VariantLegacyPricingIdentityMapping;
use TriCab\Core\Product\Application\Exception\ExplicitLegacyPricingMappingInvalid;
use TriCab\Core\Product\Application\Exception\VariantLegacyPricingIdentityNotFound;
use TriCab\Core\Product\Application\Exception\PriceListNotFound;
use TriCab\Core\Product\Contract\PriceListRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Contract\VariantLegacyPricingIdentityRepository;
use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;
use TriCab\Core\Product\Domain\ValueObject\VariantPricingLegacyContext;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;

final class ManageVariantLegacyPricingIdentityService
{
    public function __construct(
        private readonly ProductVariantRepository $variants,
        private readonly ProductRepository $products,
        private readonly VariantLegacyPricingIdentityRepository $identityMappings,
        private readonly LegacyTcRepository $legacyTc,
        private readonly PriceListRepository $priceLists,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function verify(VerifyVariantLegacyPricingIdentityCommand $command): LegacyPricingCodeInspection
    {
        $variant = $this->requireVariant($command->variantPublicId);
        $dbco = $this->resolverDbco($command->priceListPublicId);
        $code = strtoupper(trim($command->legacyPriceCode));
        $row = $this->legacyTc->inspectPricingCode($code, $dbco);

        return LegacyPricingCodeInspection::fromLegacyRow($row ?? [
            'exists' => false,
            'revisionCount' => 0,
            'latestDc' => null,
            'latestCost' => null,
            'zeroCost' => false,
            'duplicateLatestDc' => false,
        ]);
    }

    public function set(ActorContext $actor, SetVariantLegacyPricingIdentityCommand $command): VariantLegacyPricingIdentityMapping
    {
        $variant = $this->requireVariant($command->variantPublicId);
        $dbco = $this->resolverDbco($command->priceListPublicId);
        $code = strtoupper(trim($command->legacyPriceCode));
        if ($code === '') {
            throw new \InvalidArgumentException('legacyPriceCode is required.');
        }

        $inspection = $this->legacyTc->inspectPricingCode($code, $dbco);
        if ($inspection === null || !$inspection['exists']) {
            throw ExplicitLegacyPricingMappingInvalid::forCode($code, $dbco);
        }

        $previous = $this->identityMappings->findByVariantId($variant->id() ?? throw new \RuntimeException('Variant has no id.'));
        $saved = $this->identityMappings->upsert(
            $variant->id() ?? throw new \RuntimeException('Variant has no id.'),
            $variant->publicId(),
            $code,
            $this->audit->actorUserId($actor),
        );

        $product = $this->products->findById($variant->productId());
        $this->audit->record(
            actor: $actor,
            productPublicId: $product?->publicId(),
            entityTypeCode: 'variant-pricing-identity',
            entityId: $variant->id()?->toInt(),
            actionCode: $previous === null ? 'variant-legacy-pricing-mapping-created' : 'variant-legacy-pricing-mapping-changed',
            previousValues: $previous !== null ? [
                'variantPublicId' => $command->variantPublicId,
                'externalCode' => $previous->legacyPriceCode,
                'sourceSystem' => VariantPricingLegacyContext::SYSTEM_CODE,
            ] : null,
            newValues: [
                'variantPublicId' => $command->variantPublicId,
                'externalCode' => $code,
                'sourceSystem' => VariantPricingLegacyContext::SYSTEM_CODE,
            ],
        );

        return $saved;
    }

    public function remove(ActorContext $actor, string $variantPublicId): void
    {
        $variant = $this->requireVariant($variantPublicId);
        $previous = $this->identityMappings->findByVariantId($variant->id() ?? throw new \RuntimeException('Variant has no id.'));
        if ($previous === null) {
            throw VariantLegacyPricingIdentityNotFound::forVariantPublicId($variantPublicId);
        }

        $this->identityMappings->deactivate($variant->id() ?? throw new \RuntimeException('Variant has no id.'), $this->audit->actorUserId($actor));

        $product = $this->products->findById($variant->productId());
        $this->audit->record(
            actor: $actor,
            productPublicId: $product?->publicId(),
            entityTypeCode: 'variant-pricing-identity',
            entityId: $variant->id()?->toInt(),
            actionCode: 'variant-legacy-pricing-mapping-deactivated',
            previousValues: [
                'variantPublicId' => $variantPublicId,
                'externalCode' => $previous->legacyPriceCode,
                'sourceSystem' => VariantPricingLegacyContext::SYSTEM_CODE,
            ],
            newValues: null,
        );
    }

    private function requireVariant(string $variantPublicId): \TriCab\Core\Product\Domain\Entity\ProductVariant
    {
        $variant = $this->variants->findByPublicId(\TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId::fromString($variantPublicId));
        if ($variant === null) {
            throw new \InvalidArgumentException('Variant not found.');
        }

        return $variant;
    }

    private function resolverDbco(string $priceListPublicId): int
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
}
