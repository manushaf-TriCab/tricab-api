<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Resolves effective pricing context for SELF customer or authorized staff PRICE-AS.
 */
final class ResolvePricingActorContextService implements PricingActorContextResolver
{
    public const PERM_PRICE_AS = 'CUSTOMER.PRICE_AS';
    public const PERM_CUSTOMER_READ = 'CUSTOMER.READ';
    public const PERM_MARGIN_OVERRIDE = 'PRODUCT.PRICING.OVERRIDE';
    public const PERM_PRICING_MANAGE = 'PRODUCT.PRICING.MANAGE';

    public function __construct(
        private readonly ResolveCustomerPricingContextService $selfContext,
        private readonly CustomerRepository $customers,
        private readonly CustomerContactRepository $contacts,
        private readonly StaffPricingContextTokenCodec $tokens,
    ) {
    }

    public function fromAuthenticatedUser(
        ?AuthenticatedUser $user,
        ?string $pricingContextToken = null,
    ): CustomerPricingContext {
        $self = $this->selfContext->fromAuthenticatedUser($user);
        if ($user === null) {
            return $self;
        }

        $token = $pricingContextToken !== null ? trim($pricingContextToken) : '';
        if ($token === '') {
            return $self;
        }

        if (!$this->canPriceAs($user)) {
            throw new PricingActorAuthorizationException('Staff price-as-customer permission required.');
        }

        try {
            $payload = $this->tokens->decode($token);
        } catch (\InvalidArgumentException $e) {
            throw new PricingActorAuthorizationException($e->getMessage(), 0, $e);
        }

        if (strcasecmp($payload['actorUserPublicId'], $user->publicId) !== 0) {
            throw new PricingActorAuthorizationException('Pricing context token actor mismatch.');
        }

        $details = $this->customers->findDetailsByPublicId(
            CustomerPublicId::fromString($payload['customerPublicId']),
        );
        if ($details === null) {
            throw new PricingActorAuthorizationException('Selected customer not found.');
        }

        $override = $payload['overrideMargin'] ?? null;
        if ($override !== null && $override !== '') {
            if (!$this->canOverrideMargin($user)) {
                throw new PricingActorAuthorizationException('Margin override permission required.');
            }
            $override = StaffMarginOverridePolicy::normalize($override);
        } else {
            $override = null;
        }

        $country = $details->countryCode;
        $sellCurrency = match (strtoupper((string) $country)) {
            'NZ' => 'NZD',
            'US' => 'USD',
            default => 'AUD',
        };

        $contact = $this->resolveContactForCustomer(
            $payload['customerPublicId'],
            isset($payload['contactPublicId']) && is_string($payload['contactPublicId']) ? $payload['contactPublicId'] : null,
        );

        return new CustomerPricingContext(
            customerPublicId: strtolower($payload['customerPublicId']),
            countryCode: $country,
            territoryCode: $details->territory,
            marketCode: $details->marketCode,
            sellCurrency: $sellCurrency,
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
            overrideMarginPercent: $override,
            customerCode: $details->customerCode ?? null,
            customerDisplayName: $details->customerName,
            contactPublicId: $contact['publicId'] ?? null,
            contactDisplayName: $contact['displayName'] ?? null,
            contactTitle: $contact['title'] ?? null,
        );
    }

    /**
     * @return array{publicId: string, displayName: string, title: ?string}|null
     */
    private function resolveContactForCustomer(string $customerPublicId, ?string $contactPublicId): ?array
    {
        if ($contactPublicId === null || $contactPublicId === '') {
            return null;
        }

        $contact = $this->contacts->findDetailsByPublicId(CustomerContactPublicId::fromString($contactPublicId));
        if ($contact === null || !$contact->isActive) {
            throw new PricingActorAuthorizationException('Selected contact not found.');
        }
        if (strcasecmp($contact->customerPublicId, $customerPublicId) !== 0) {
            throw new PricingActorAuthorizationException('Selected contact does not belong to customer.');
        }

        return [
            'publicId' => $contact->publicId,
            'displayName' => $contact->displayName,
            'title' => $contact->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStaffContextResponse(
        string $token,
        CustomerPricingContext $context,
        ?string $overrideMargin = null,
        ?string $marginSource = null,
    ): array {
        return [
            'pricingContextToken' => $token,
            'mode' => $context->pricingMode,
            'customerId' => $context->customerPublicId,
            'customerCode' => $context->customerCode,
            'displayName' => $context->customerDisplayName,
            'country' => $context->countryCode,
            'territory' => $context->territoryCode,
            'market' => $context->marketCode,
            'currency' => $context->sellCurrency,
            'pricingStatus' => 'OK',
            'contactId' => $context->contactPublicId,
            'contactDisplayName' => $context->contactDisplayName,
            'contactTitle' => $context->contactTitle,
            'overrideMargin' => $overrideMargin ?? $context->overrideMarginPercent,
            'marginSource' => $marginSource,
            'allowedMarginOverrides' => StaffMarginOverridePolicy::allowedValues(),
        ];
    }

    public function selectCustomer(
        AuthenticatedUser $user,
        string $customerPublicId,
        ?string $contactPublicId = null,
    ): array {
        if (!$this->canPriceAs($user)) {
            throw new PricingActorAuthorizationException('Staff price-as-customer permission required.');
        }

        $details = $this->customers->findDetailsByPublicId(CustomerPublicId::fromString($customerPublicId));
        if ($details === null) {
            throw new PricingActorAuthorizationException('Selected customer not found.');
        }

        $contact = $this->resolveContactForCustomer($customerPublicId, $contactPublicId);
        $token = $this->tokens->issue(
            $user->publicId,
            $customerPublicId,
            null,
            $contact['publicId'] ?? null,
        );
        $country = $details->countryCode;
        $sellCurrency = match (strtoupper((string) $country)) {
            'NZ' => 'NZD',
            'US' => 'USD',
            default => 'AUD',
        };

        $context = new CustomerPricingContext(
            customerPublicId: $customerPublicId,
            countryCode: $country,
            territoryCode: $details->territory,
            marketCode: $details->marketCode,
            sellCurrency: $sellCurrency,
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
            overrideMarginPercent: null,
            customerCode: $details->customerCode ?? null,
            customerDisplayName: $details->customerName,
            contactPublicId: $contact['publicId'] ?? null,
            contactDisplayName: $contact['displayName'] ?? null,
            contactTitle: $contact['title'] ?? null,
        );

        return $this->buildStaffContextResponse($token, $context);
    }

    public function applyMarginOverride(AuthenticatedUser $user, string $pricingContextToken, string|int $margin): array
    {
        if (!$this->canOverrideMargin($user)) {
            throw new PricingActorAuthorizationException('Margin override permission required.');
        }

        $payload = $this->tokens->decode($pricingContextToken);
        if (strcasecmp($payload['actorUserPublicId'], $user->publicId) !== 0) {
            throw new PricingActorAuthorizationException('Pricing context token actor mismatch.');
        }

        $normalized = StaffMarginOverridePolicy::normalize($margin);
        $contactPublicId = isset($payload['contactPublicId']) && is_string($payload['contactPublicId'])
            ? $payload['contactPublicId']
            : null;
        $token = $this->tokens->issue(
            $user->publicId,
            $payload['customerPublicId'],
            $normalized,
            $contactPublicId,
        );
        $context = $this->fromAuthenticatedUser($user, $token);

        return $this->buildStaffContextResponse($token, $context, $normalized, 'STAFF_OVERRIDE');
    }

    public function clearOverride(AuthenticatedUser $user, string $pricingContextToken): array
    {
        $payload = $this->tokens->decode($pricingContextToken);
        if (strcasecmp($payload['actorUserPublicId'], $user->publicId) !== 0) {
            throw new PricingActorAuthorizationException('Pricing context token actor mismatch.');
        }
        if (!$this->canPriceAs($user)) {
            throw new PricingActorAuthorizationException('Staff price-as-customer permission required.');
        }

        $contactPublicId = isset($payload['contactPublicId']) && is_string($payload['contactPublicId'])
            ? $payload['contactPublicId']
            : null;

        return $this->selectCustomer($user, $payload['customerPublicId'], $contactPublicId);
    }

    public function canPriceAs(AuthenticatedUser $user): bool
    {
        return in_array(self::PERM_PRICE_AS, $user->permissions, true)
            || in_array(self::PERM_CUSTOMER_READ, $user->permissions, true);
    }

    public function canOverrideMargin(AuthenticatedUser $user): bool
    {
        return in_array(self::PERM_MARGIN_OVERRIDE, $user->permissions, true)
            || in_array(self::PERM_PRICING_MANAGE, $user->permissions, true);
    }
}
