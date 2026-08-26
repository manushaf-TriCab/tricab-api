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
use TriCab\Core\Product\Application\Pricing\CustomerPricingContextResolver;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;

/**
 * Batch customer-safe variant sell prices for the size modal.
 * POST /api/v1/products/{slug}/variants/pricing
 *
 * Body: { "variantIds": ["..."] }
 * Does not accept margin/dbco/priceListId from the client.
 */
final class PostCustomerVariantPricingBatchAction
{
    private const MAX_BATCH = 40;

    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly CustomerPricingContextResolver $contextResolver,
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
            $context = $this->contextResolver->fromAuthenticatedUser($user);

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }
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

            // Reject client attempts to inject commercial parameters.
            foreach (['marginPercent', 'dbco', 'priceListId', 'baseCost', 'customerId'] as $forbidden) {
                if (array_key_exists($forbidden, $body)) {
                    return $this->responder->error('VALIDATION_FAILED', 'Client may not supply ' . $forbidden . '.', 422);
                }
            }

            $staffDiag = false;
            if ($user instanceof AuthenticatedUser && in_array('PRODUCT.PRICING.VIEW', $user->permissions, true)) {
                $staffDiag = ($body['includeDiagnostic'] ?? false) === true;
            }

            $items = [];
            foreach ($variantIds as $variantId) {
                $resolved = $this->pricing->resolve($variantId, $context, $staffDiag);
                if ($resolved instanceof CustomerVariantSellPriceDiagnostic) {
                    $items[] = $resolved->toStaffArray();
                } else {
                    $payload = $resolved->toCustomerArray();
                    // Defence in depth — never leak baseCost keys
                    unset($payload['baseCost'], $payload['marginPercent'], $payload['dbco']);
                    $items[] = $payload;
                }
            }

            return $this->responder->success([
                'authState' => $context->authState,
                'currency' => $context->sellCurrency,
                'items' => $items,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
