<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Pricing\FakeLegacyCostCalculationGateway;
use TriCab\Core\Product\Application\Pricing\CostResolution;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantCommercialCatalogRepository;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec;

final class Phase137f1SelectedCustomerPricingTest extends TestCase
{
    public function test_bne_like_missing_margin_uses_default_zero_not_unavailable(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PJ2D/3C25BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PJ2D/3C25BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '10',
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '10',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed('v-bne', [
            'variantPublicId' => 'v-bne',
            'variantCode' => 'BVA-PJ2D/3C25BK',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'BVA-PJ2D/3C25BK',
            'azureBase' => null,
        ]);

        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'CONSULT1', 3, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'control',
        ]);

        $svc = new ResolveCustomerSellPriceService(
            $catalog,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
        );

        $bneCtx = new CustomerPricingContext(
            customerPublicId: 'cust-bne',
            countryCode: 'AU',
            territoryCode: 'QLD-METRO',
            marketCode: 'ENERGY',
            sellCurrency: 'AUD',
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
            customerCode: 'BNE-ENR-001',
            customerDisplayName: 'Brisbane Energy Systems',
        );

        $diag = $svc->resolve('v-bne', $bneCtx, true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $diag);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $diag->customer->status);
        self::assertSame('0', $diag->marginPercent);
        self::assertContains('NO_MARGIN_RULE', $diag->trace);
        self::assertContains('MARGIN_DEFAULT:0', $diag->trace);
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $diag->trace);
        self::assertNotSame(CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE, $diag->customer->status);
    }

    public function test_staff_margin_override_changes_automatic_zero_price(): void
    {
        $svc = $this->pricingWithMarginSeed();
        $bneCtx = new CustomerPricingContext(
            customerPublicId: 'cust-bne',
            countryCode: 'AU',
            territoryCode: 'QLD-METRO',
            marketCode: 'ENERGY',
            sellCurrency: 'AUD',
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
        );

        $automatic = $svc->resolve('v-bne', $bneCtx, false, null, false, '100');
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $automatic->status);
        self::assertNotNull($automatic->unitSellPrice);

        $overridden = $svc->resolve('v-bne', $bneCtx->withOverrideMargin('31'), false, null, false, '100');
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $overridden->status);
        self::assertNotNull($overridden->unitSellPrice);
        self::assertNotSame($automatic->unitSellPrice, $overridden->unitSellPrice);
    }

    public function test_pricing_context_token_with_contact_accepted(): void
    {
        $contacts = new class implements \TriCab\Core\Customer\Contract\CustomerContactRepository {
            public function listByCustomer(\TriCab\Core\Customer\Domain\ValueObject\CustomerId $customerId, \TriCab\Core\Customer\Application\DTO\ContactListCriteria $criteria): \TriCab\Core\Customer\Application\DTO\ContactListResult
            {
                unset($customerId, $criteria);
                throw new \BadMethodCallException();
            }

            public function findDetailsByPublicId(\TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId $publicId): ?\TriCab\Core\Customer\Application\DTO\CustomerContactDetails
            {
                return new \TriCab\Core\Customer\Application\DTO\CustomerContactDetails(
                    publicId: $publicId->toString(),
                    customerPublicId: '52A45BFB-AC8F-F111-B337-6045BD3F9734',
                    displayName: 'Riley Admin',
                    email: null,
                    normalizedEmail: null,
                    position: 'Office Administrator',
                    phone: null,
                    mobile: null,
                    isPrimary: true,
                    isActive: true,
                    portalAccessStatus: 'NONE',
                    legacyContactId: null,
                    createdAtUtc: '2026-01-01T00:00:00Z',
                    updatedAtUtc: '2026-01-01T00:00:00Z',
                );
            }

            public function findIdByPublicId(\TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId $publicId): ?\TriCab\Core\Customer\Domain\ValueObject\CustomerContactId
            {
                unset($publicId);
                return null;
            }

            public function create(
                \TriCab\Core\Customer\Domain\ValueObject\CustomerId $customerId,
                string $displayName,
                ?\TriCab\Core\Identity\Domain\ValueObject\EmailAddress $email,
                ?string $position,
                ?string $phone,
                ?string $mobile,
                bool $isPrimary,
                bool $isActive,
                \TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus $portalAccessStatus,
                ?\TriCab\Core\Customer\Domain\ValueObject\LegacyContactId $legacyContactId,
                \TriCab\Core\Identity\Domain\ValueObject\UserId $actorUserId,
            ): \TriCab\Core\Customer\Application\DTO\CustomerContactDetails {
                unset($customerId, $displayName, $email, $position, $phone, $mobile, $isPrimary, $isActive, $portalAccessStatus, $legacyContactId, $actorUserId);
                throw new \BadMethodCallException();
            }

            public function update(
                \TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId $publicId,
                string $displayName,
                ?\TriCab\Core\Identity\Domain\ValueObject\EmailAddress $email,
                ?string $position,
                ?string $phone,
                ?string $mobile,
                \TriCab\Core\Identity\Domain\ValueObject\UserId $actorUserId,
            ): \TriCab\Core\Customer\Application\DTO\CustomerContactDetails {
                unset($publicId, $displayName, $email, $position, $phone, $mobile, $actorUserId);
                throw new \BadMethodCallException();
            }

            public function changeStatus(
                \TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId $publicId,
                bool $isActive,
                \TriCab\Core\Identity\Domain\ValueObject\UserId $actorUserId,
            ): \TriCab\Core\Customer\Application\DTO\CustomerContactDetails {
                unset($publicId, $isActive, $actorUserId);
                throw new \BadMethodCallException();
            }

            public function changePortalAccessStatus(
                \TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId $publicId,
                \TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus $status,
                \TriCab\Core\Identity\Domain\ValueObject\UserId $actorUserId,
            ): \TriCab\Core\Customer\Application\DTO\CustomerContactDetails {
                unset($publicId, $status, $actorUserId);
                throw new \BadMethodCallException();
            }

            public function findByNormalizedEmail(\TriCab\Core\Identity\Domain\ValueObject\EmailAddress $email, ?\TriCab\Core\Customer\Domain\ValueObject\CustomerId $customerScope = null): array
            {
                unset($email, $customerScope);
                return [];
            }
        };

        $customers = $this->createMock(\TriCab\Core\Customer\Contract\CustomerRepository::class);
        $customers->method('findDetailsByPublicId')->willReturn(new \TriCab\Core\Customer\Application\DTO\CustomerDetails(
            publicId: '52A45BFB-AC8F-F111-B337-6045BD3F9734',
            customerCode: 'BNE-ENR-001',
            customerName: 'Brisbane Energy Systems',
            status: 'active',
            isActive: true,
            addressLine1: null,
            addressLine2: null,
            suburb: null,
            postcode: null,
            countryCode: 'AU',
            marketCode: 'ENERGY',
            salesRep: null,
            territory: 'QLD-METRO',
            phone: null,
            legacyCustomerId: null,
            createdAtUtc: '2026-01-01T00:00:00Z',
            updatedAtUtc: '2026-01-01T00:00:00Z',
        ));

        $links = new class implements \TriCab\Core\Customer\Contract\CustomerContactUserRepository {
            public function link(
                \TriCab\Core\Customer\Domain\ValueObject\CustomerContactId $contactId,
                \TriCab\Core\Identity\Domain\ValueObject\UserId $userId,
                bool $isPrimary,
                ?\TriCab\Core\Identity\Domain\ValueObject\UserId $createdByUserId,
            ): \TriCab\Core\Customer\Application\DTO\CustomerContactUserLinkDetails {
                unset($contactId, $userId, $isPrimary, $createdByUserId);
                throw new \BadMethodCallException();
            }

            public function unlink(\TriCab\Core\Customer\Domain\ValueObject\CustomerContactId $contactId, \TriCab\Core\Identity\Domain\ValueObject\UserId $userId): void
            {
                unset($contactId, $userId);
            }

            public function hasActiveLink(\TriCab\Core\Customer\Domain\ValueObject\CustomerContactId $contactId, \TriCab\Core\Identity\Domain\ValueObject\UserId $userId): bool
            {
                unset($contactId, $userId);
                return false;
            }

            public function findActivePrimaryUser(\TriCab\Core\Customer\Domain\ValueObject\CustomerContactId $contactId): ?\TriCab\Core\Identity\Domain\ValueObject\UserId
            {
                unset($contactId);
                return null;
            }

            public function listActiveLinksForUser(\TriCab\Core\Identity\Domain\ValueObject\UserId $userId): array
            {
                unset($userId);
                return [];
            }

            public function listPortalUsersForCustomer(\TriCab\Core\Customer\Domain\ValueObject\CustomerId $customerId): array
            {
                unset($customerId);
                return [];
            }
        };

        $selfContext = new \TriCab\Core\Product\Application\Pricing\ResolveCustomerPricingContextService(
            new \TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService($links),
            $customers,
        );

        $actor = new ResolvePricingActorContextService($selfContext, $customers, $contacts, new StaffPricingContextTokenCodec('test-secret-at-least-32-bytes-long!!'));
        $user = new \TriCab\Core\Identity\Application\DTO\AuthenticatedUser(
            userId: 1,
            publicId: 'actor-staff-uuid-0001',
            emailAddress: 'staff@test.local',
            displayName: 'Staff',
            status: 'active',
            roles: [],
            permissions: [ResolvePricingActorContextService::PERM_PRICE_AS],
        );

        $issued = $actor->selectCustomer($user, '52A45BFB-AC8F-F111-B337-6045BD3F9734', '58A45BFB-AC8F-F111-B337-6045BD3F9734');
        $ctx = $actor->fromAuthenticatedUser($user, $issued['pricingContextToken']);

        self::assertSame('BNE-ENR-001', $ctx->customerCode);
        self::assertSame('Riley Admin', $ctx->contactDisplayName);
    }

    private function pricingWithMarginSeed(): ResolveCustomerSellPriceService
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PJ2D/3C25BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PJ2D/3C25BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '10',
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '10',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed('v-bne', [
            'variantPublicId' => 'v-bne',
            'variantCode' => 'BVA-PJ2D/3C25BK',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'BVA-PJ2D/3C25BK',
            'azureBase' => null,
        ]);

        return new ResolveCustomerSellPriceService(
            $catalog,
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
        );
    }
}
