<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\StaffEligibilityPolicy;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;

/**
 * Batch customer-safe variant sell prices for the size modal.
 * POST /api/v1/products/{slug}/variants/pricing
 *
 * Optional staff price-as: pricingContextToken / X-Pricing-Context-Token
 * Optional length (metres) for MAKE Cost API pricing.
 * Does not accept margin/dbco/priceListId/customerId as commercial authority.
 */
final class PostCustomerVariantPricingBatchAction
{
    private const MAX_BATCH = 40;

    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly PricingActorContextResolver $actorContext,
        private readonly ResolveCustomerSellPriceService $pricing,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $user = $this->requestUser->fromRequest($request);

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }

            // Reject client attempts to inject commercial parameters as authority.
            foreach (['marginPercent', 'dbco', 'priceListId', 'baseCost', 'customerId', 'defaultCost', 'calculatedCostPerMeter'] as $forbidden) {
                if (array_key_exists($forbidden, $body)) {
                    return $this->responder->error('VALIDATION_FAILED', 'Client may not supply ' . $forbidden . '.', 422);
                }
            }

            $token = trim((string) ($body['pricingContextToken'] ?? $request->getHeaderLine('X-Pricing-Context-Token')));
            $context = $this->actorContext->fromAuthenticatedUser($user, $token !== '' ? $token : null);

            $rawIds = $body['variantIds'] ?? [];
            if (!is_array($rawIds)) {
                return $this->responder->error('VALIDATION_FAILED', 'variantIds must be an array.', 422);
            }

            $variantIds = [];
            foreach ($rawIds as $id) {
                if (is_string($id) && trim($id) !== '') {
                    $variantIds[] = trim($id);
                }
            }
            $variantIds = array_values(array_unique($variantIds));
            if (count($variantIds) > self::MAX_BATCH) {
                return $this->responder->error('VALIDATION_FAILED', 'Too many variantIds (max ' . self::MAX_BATCH . ').', 422);
            }

            $requestedLength = null;
            if (array_key_exists('length', $body) && $body['length'] !== null && $body['length'] !== '') {
                $rawLength = is_numeric($body['length']) ? (string) $body['length'] : trim((string) $body['length']);
                if (!$this->pricing->isValidPositiveLength($rawLength)) {
                    return $this->responder->error('INVALID_LENGTH', 'length must be a positive number.', 422);
                }
                $requestedLength = $rawLength;
            }

            $staffDiag = self::allowsStaffDiagnostics($user) && ($body['includeDiagnostic'] ?? false) === true;

            $items = [];
            foreach ($variantIds as $variantId) {
                try {
                    $resolved = $this->pricing->resolve($variantId, $context, $staffDiag, null, false, $requestedLength);
                } catch (Throwable $e) {
                    unset($e);
                    $items[] = [
                        'variantId' => $variantId,
                        'status' => CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE,
                        'availability' => CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
                        'unitPrice' => null,
                        'currency' => null,
                        'uom' => 'm',
                        'displayMessage' => 'Pricing temporarily unavailable',
                        'canAddToRfq' => false,
                    ];
                    continue;
                }
                if ($resolved instanceof CustomerVariantSellPriceDiagnostic) {
                    $items[] = $resolved->toStaffArray();
                } else {
                    $payload = $resolved->toCustomerArray();
                    unset(
                        $payload['baseCost'],
                        $payload['marginPercent'],
                        $payload['dbco'],
                        $payload['defaultCost'],
                        $payload['calculatedCostPerMeter'],
                    );
                    $items[] = $payload;
                }
            }

            return $this->responder->success([
                'authState' => $context->authState,
                'currency' => $context->sellCurrency,
                'pricingMode' => $context->pricingMode,
                'customerId' => $context->customerPublicId,
                'customerCode' => $context->customerCode,
                'customerDisplayName' => $context->customerDisplayName,
                'overrideMargin' => $context->overrideMarginPercent,
                'length' => $requestedLength,
                'items' => $items,
            ]);
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('CONTEXT_INVALID', $e->getMessage(), 403);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    /**
     * Cost diagnostics are for eligible staff holding PRODUCT.PRICING.VIEW only.
     */
    public static function allowsStaffDiagnostics(?AuthenticatedUser $user): bool
    {
        return $user instanceof AuthenticatedUser
            && StaffEligibilityPolicy::isEligible($user->roles)
            && in_array('PRODUCT.PRICING.VIEW', $user->permissions, true);
    }
}
