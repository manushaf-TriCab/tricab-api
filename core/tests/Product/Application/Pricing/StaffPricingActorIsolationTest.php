<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\PermissionAssurancePolicy;
use TriCab\Core\Identity\Domain\Service\StaffEligibilityPolicy;
use TriCab\Core\Identity\Domain\ValueObject\AuthenticationAssurance;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;

/**
 * Security S1/S1.2: staff identity is proven by an explicit staff role, never by
 * a permission; customer-oriented roles disqualify even alongside staff roles;
 * staff price-as needs CUSTOMER.PRICE_AS on an eligible staff identity and a
 * passkey or Entra sign-in. CUSTOMER.READ never grants it.
 */
final class StaffPricingActorIsolationTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function roleSets(): iterable
    {
        yield 'employee' => [['EMPLOYEE'], StaffEligibilityPolicy::ELIGIBLE];
        yield 'administrator' => [['ADMINISTRATOR'], StaffEligibilityPolicy::ELIGIBLE];
        yield 'employee with specialist role' => [['EMPLOYEE', 'RFQ_SALES', 'CUSTOMER_CONTACT_COORDINATOR'], StaffEligibilityPolicy::ELIGIBLE];
        yield 'employee still carrying guest' => [['EMPLOYEE', 'GUEST'], StaffEligibilityPolicy::ELIGIBLE];
        yield 'lower-case and padded' => [[' employee '], StaffEligibilityPolicy::ELIGIBLE];
        yield 'customer user' => [['CUSTOMER_USER'], StaffEligibilityPolicy::CUSTOMER_ACTOR];
        yield 'customer admin' => [['CUSTOMER_ADMIN'], StaffEligibilityPolicy::CUSTOMER_ACTOR];
        yield 'customer applicant' => [['CUSTOMER_APPLICANT'], StaffEligibilityPolicy::CUSTOMER_ACTOR];
        yield 'newbie' => [['NEWBIE'], StaffEligibilityPolicy::CUSTOMER_ACTOR];
        yield 'future customer role by prefix' => [['CUSTOMER_BUYER'], StaffEligibilityPolicy::CUSTOMER_ACTOR];
        yield 'future portal role by prefix' => [['PORTAL_COMPANY_ADMIN'], StaffEligibilityPolicy::CUSTOMER_ACTOR];
        yield 'administrator with customer role' => [['EMPLOYEE', 'ADMINISTRATOR', 'customer_user '], StaffEligibilityPolicy::CUSTOMER_ACTOR];
        yield 'employee with applicant role' => [['EMPLOYEE', 'CUSTOMER_APPLICANT'], StaffEligibilityPolicy::CUSTOMER_ACTOR];
        yield 'guest alone' => [['GUEST'], StaffEligibilityPolicy::NOT_STAFF];
        yield 'specialist role alone' => [['RFQ_SALES'], StaffEligibilityPolicy::NOT_STAFF];
        yield 'contact-report role alone' => [['CUSTOMER_CONTACT_USER'], StaffEligibilityPolicy::NOT_STAFF];
        yield 'unknown custom role' => [['WAREHOUSE_SUPERVISOR'], StaffEligibilityPolicy::NOT_STAFF];
        yield 'no roles' => [[], StaffEligibilityPolicy::NOT_STAFF];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('roleSets')]
    public function test_staff_eligibility_is_decided_by_role(array $roles, string $expected): void
    {
        self::assertSame($expected, StaffEligibilityPolicy::evaluate($roles));
        self::assertSame($expected === StaffEligibilityPolicy::ELIGIBLE, StaffEligibilityPolicy::isEligible($roles));
    }

    public function test_unknown_custom_role_with_staff_like_permissions_is_not_staff(): void
    {
        $user = $this->user(['WAREHOUSE_SUPERVISOR'], ['ACCOUNT.LOGIN', 'CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.MANAGE', 'USER.READ']);

        self::assertFalse(StaffEligibilityPolicy::isEligible($user->roles));
        self::assertFalse(ResolvePricingActorContextService::actorMayPriceAs($user));
        self::assertFalse(ResolvePricingActorContextService::actorMayOverrideMargin($user));
    }

    public function test_customer_read_alone_never_grants_price_as(): void
    {
        self::assertFalse(ResolvePricingActorContextService::actorMayPriceAs($this->user(['EMPLOYEE'], ['CUSTOMER.READ'])));
        self::assertFalse(ResolvePricingActorContextService::actorMayPriceAs($this->user(['ADMINISTRATOR'], ['CUSTOMER.READ', 'CUSTOMER.UPDATE'])));
    }

    public function test_staff_with_price_as_may_price_as(): void
    {
        self::assertTrue(ResolvePricingActorContextService::actorMayPriceAs($this->user(['EMPLOYEE'], ['CUSTOMER.PRICE_AS'])));
        self::assertTrue(ResolvePricingActorContextService::actorMayPriceAs($this->user(['ADMINISTRATOR'], ['CUSTOMER.PRICE_AS'])));
    }

    public function test_customer_and_guest_actors_with_price_as_are_refused(): void
    {
        $roleSets = [
            ['CUSTOMER_USER'], ['CUSTOMER_ADMIN'], ['CUSTOMER_APPLICANT'], ['GUEST'],
            ['EMPLOYEE', 'CUSTOMER_USER'], ['ADMINISTRATOR', 'CUSTOMER_ADMIN'],
        ];
        foreach ($roleSets as $roles) {
            $user = $this->user($roles, ['CUSTOMER.PRICE_AS', 'CUSTOMER.READ', 'PRODUCT.PRICING.OVERRIDE']);
            self::assertFalse(ResolvePricingActorContextService::actorMayPriceAs($user), implode(',', $roles));
            self::assertFalse(ResolvePricingActorContextService::actorMayOverrideMargin($user), implode(',', $roles));
        }
    }

    public function test_staff_cart_fields_require_staff_selected_mode_and_price_as(): void
    {
        $staffSelected = $this->context(CustomerPricingContext::MODE_STAFF_SELECTED);
        $customerMode = $this->context(CustomerPricingContext::MODE_SELF);

        self::assertTrue(ResolvePricingActorContextService::isStaffPricingActor($this->user(['EMPLOYEE'], ['CUSTOMER.PRICE_AS']), $staffSelected));
        self::assertFalse(ResolvePricingActorContextService::isStaffPricingActor($this->user(['EMPLOYEE'], ['PRODUCT.PRICING.MANAGE']), $staffSelected));
        self::assertFalse(ResolvePricingActorContextService::isStaffPricingActor($this->user(['EMPLOYEE'], ['CUSTOMER.PRICE_AS']), $customerMode));
        self::assertFalse(ResolvePricingActorContextService::isStaffPricingActor($this->user(['EMPLOYEE'], ['CUSTOMER.READ']), $staffSelected));
        self::assertFalse(ResolvePricingActorContextService::isStaffPricingActor($this->user(['RFQ_SALES'], ['CUSTOMER.PRICE_AS']), $staffSelected));
        self::assertFalse(ResolvePricingActorContextService::isStaffPricingActor(
            $this->user(['CUSTOMER_USER'], ['CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.OVERRIDE', 'PRODUCT.PRICING.MANAGE']),
            $staffSelected,
        ));
    }

    public function test_price_as_and_margin_override_need_passkey_or_entra(): void
    {
        foreach (['CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.OVERRIDE'] as $code) {
            $permission = PermissionCode::fromString($code);
            self::assertFalse(PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::forProvider('email_otp')), $code);
            self::assertFalse(PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::forProvider('google')), $code);
            self::assertFalse(PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::forProvider('apple')), $code);
            self::assertTrue(PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::forProvider('passkey')), $code);
            self::assertTrue(PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::forProvider('microsoft_entra')), $code);
        }
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function user(array $roles, array $permissions): AuthenticatedUser
    {
        return new AuthenticatedUser(
            userId: 9001,
            publicId: '00000000-0000-4000-8000-000000009001',
            emailAddress: 'synthetic.actor@example.test',
            displayName: 'Synthetic Actor',
            status: 'active',
            roles: $roles,
            permissions: $permissions,
        );
    }

    private function context(string $mode): CustomerPricingContext
    {
        return new CustomerPricingContext(
            customerPublicId: '00000000-0000-4000-8000-000000000c01',
            countryCode: 'AU',
            territoryCode: 'VIC',
            marketCode: 'CONSULT1',
            sellCurrency: 'AUD',
            authState: 'OK',
            pricingMode: $mode,
        );
    }
}
