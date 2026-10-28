<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Contract\VariantAvailabilityRepository;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
use TriCab\Core\Commerce\Domain\ValueObject\MarginSource;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class RepriceCartService
{
    public const CHANGE_UNCHANGED = 'UNCHANGED';
    public const CHANGE_PRICE_CHANGED = 'PRICE_CHANGED';
    public const CHANGE_TBC = 'TBC';
    public const CHANGE_PRICE_NOT_AVAILABLE = 'PRICE_NOT_AVAILABLE';
    public const CHANGE_CUSTOMER_CONTEXT_CHANGED = 'CUSTOMER_CONTEXT_CHANGED';

    public function __construct(
        private readonly CartRepository $carts,
        private readonly PricingActorContextResolver $actorContext,
        private readonly ResolveCustomerSellPriceService $pricing,
        private readonly VariantAvailabilityRepository $availability,
        private readonly CartDtoAssembler $assembler,
        private readonly CartEffectiveCustomerResolver $customerResolver,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        AuthenticatedUser $user,
        bool $commit = false,
        ?string $pricingContextToken = null,
        ?string $previousCustomerPublicId = null,
    ): array {
        $context = $this->actorContext->fromAuthenticatedUser($user, $pricingContextToken);
        if ($context->authState === CustomerSellPriceStatus::LOGIN_REQUIRED) {
            return [
                'accepted' => false,
                'status' => CustomerSellPriceStatus::LOGIN_REQUIRED,
                'displayMessage' => 'Sign in to reprice cart.',
            ];
        }

        $currency = strtoupper($context->sellCurrency !== '' ? $context->sellCurrency : 'AUD');
        $pricingMode = CartPricingMode::fromString($context->pricingMode);
        $effectiveCustomerId = $this->customerResolver->resolve($context);

        $cart = $this->carts->findOpenCartForActor(
            $user->userId,
            $effectiveCustomerId,
            $pricingMode,
            $currency,
        );
        if ($cart === null) {
            return [
                'accepted' => true,
                'commit' => $commit,
                'changes' => [],
                'cart' => null,
            ];
        }

        $contextChanged = $previousCustomerPublicId !== null
            && $previousCustomerPublicId !== ''
            && $previousCustomerPublicId !== ($context->customerPublicId ?? '');

        $proposed = [];
        foreach ($cart->lines() as $line) {
            $resolved = $this->pricing->resolve($line->variantPublicId(), $context, false, null, false, $line->length());
            $result = $resolved instanceof CustomerVariantSellPriceDiagnostic ? $resolved->customer : $resolved;

            $change = self::CHANGE_UNCHANGED;
            if ($contextChanged) {
                $change = self::CHANGE_CUSTOMER_CONTEXT_CHANGED;
            } elseif ($result->status === CustomerSellPriceStatus::TBC) {
                $change = self::CHANGE_TBC;
            } elseif (!in_array($result->status, [CustomerSellPriceStatus::RESOLVED], true)) {
                $change = self::CHANGE_PRICE_NOT_AVAILABLE;
            } elseif ($line->unitSellPrice() === null || $result->unitSellPrice === null) {
                $change = self::CHANGE_PRICE_CHANGED;
            } elseif (bccomp($line->unitSellPrice(), $result->unitSellPrice, 6) !== 0) {
                $change = self::CHANGE_PRICE_CHANGED;
            }

            $isTbc = $result->status === CustomerSellPriceStatus::TBC
                || $result->unitSellPrice === null
                || ($result->unitSellPrice !== null && bccomp($result->unitSellPrice, '0', 6) <= 0);
            $unitPrice = $isTbc ? null : $result->unitSellPrice;
            $pricingStatus = $isTbc ? CustomerSellPriceStatus::TBC : (
                $result->status === CustomerSellPriceStatus::RESOLVED
                    ? CustomerSellPriceStatus::RESOLVED
                    : $result->status
            );
            $lineCurrency = strtoupper((string) ($result->currencyCode ?? $line->currencyCode()));
            $marginSource = $isTbc
                ? MarginSource::TBC
                : ($context->overrideMarginPercent !== null && $context->overrideMarginPercent !== ''
                    ? MarginSource::STAFF_OVERRIDE
                    : MarginSource::TMMKTM);
            $availability = $this->availability->findAvailability($line->variantPublicId());
            if ($availability === CustomerSellPriceStatus::AVAILABILITY_UNKNOWN && $result->availability !== '') {
                $availability = $result->availability;
            }
            $uom = $result->uomCode ?? $result->uomSymbol ?? $line->uomCode();
            $fingerprint = CartPricingFingerprint::compute(
                $line->variantPublicId(),
                $context->customerPublicId,
                $context->pricingMode,
                $lineCurrency,
                $pricingStatus,
                $unitPrice,
                $uom,
                $availability,
                $line->purchaseOptionKey(),
                $context->overrideMarginPercent !== null && $context->overrideMarginPercent !== '',
            );
            $lineTotal = CartLineMath::lineTotal($unitPrice, $line->quantity(), $line->length());

            $proposed[] = [
                'lineId' => $line->publicId()->toString(),
                'change' => $change,
                'previousUnitPrice' => $line->unitSellPrice(),
                'proposedUnitPrice' => $unitPrice,
                'previousLineTotal' => $line->lineTotal(),
                'proposedLineTotal' => $lineTotal,
                'pricingStatus' => $pricingStatus,
                'apply' => [
                    'pricingStatus' => $pricingStatus,
                    'unitSellPrice' => $unitPrice,
                    'lineTotal' => $lineTotal,
                    'currencyCode' => $lineCurrency,
                    'marginSource' => $marginSource,
                    'pricingFingerprint' => $fingerprint,
                    'availability' => $availability,
                    'uomCode' => $uom,
                    'rowVersion' => $line->rowVersion()->toBase64(),
                ],
            ];
        }

        if ($commit) {
            $cart = $this->transaction->run(function () use ($cart, $proposed, $user, $effectiveCustomerId, $pricingMode, $currency) {
                foreach ($proposed as $item) {
                    $line = null;
                    foreach ($cart->lines() as $candidate) {
                        if ($candidate->publicId()->toString() === $item['lineId']) {
                            $line = $candidate;
                            break;
                        }
                    }
                    if ($line === null || $cart->id() === null) {
                        continue;
                    }
                    $apply = $item['apply'];
                    $updated = $line->withPricing(
                        pricingStatus: $apply['pricingStatus'],
                        unitSellPrice: $apply['unitSellPrice'],
                        lineTotal: $apply['lineTotal'],
                        currencyCode: $apply['currencyCode'],
                        marginSource: $apply['marginSource'],
                        pricingFingerprint: $apply['pricingFingerprint'],
                        resolvedAtUtc: gmdate('c'),
                        uomCode: $apply['uomCode'],
                        availability: $apply['availability'],
                    );
                    $this->carts->updateLine(
                        $cart->id(),
                        $line->publicId(),
                        $updated,
                        $line->rowVersion(),
                        $user->userId,
                    );
                }
                $this->carts->touchCartUpdatedAt($cart->id(), $user->userId);

                return $this->carts->findOpenCartForActor(
                    $user->userId,
                    $effectiveCustomerId,
                    $pricingMode,
                    $currency,
                ) ?? $cart;
            });
        }

        $includeStaff = ResolvePricingActorContextService::isStaffPricingActor($user, $context);

        $changes = array_map(static function (array $row): array {
            unset($row['apply']);

            return $row;
        }, $proposed);

        return [
            'accepted' => true,
            'commit' => $commit,
            'changes' => $changes,
            'cart' => $this->assembler->toArray($cart, $context, $user, $includeStaff),
        ];
    }
}
