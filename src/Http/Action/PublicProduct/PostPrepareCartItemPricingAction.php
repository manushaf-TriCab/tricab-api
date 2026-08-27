<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Pricing\PrepareCartItemPricingService;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;

/**
 * POST /api/v1/products/{slug}/cart/items/prepare
 *
 * Server-authoritative cart line commercial snapshot.
 * Durable cart persistence is available via POST /api/v1/cart/items.
 */
final class PostPrepareCartItemPricingAction
{
    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly PricingActorContextResolver $actorContext,
        private readonly PrepareCartItemPricingService $prepare,
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

            foreach (['marginPercent', 'dbco', 'priceListId', 'baseCost', 'customerId', 'lineTotal'] as $forbidden) {
                if (array_key_exists($forbidden, $body)) {
                    return $this->responder->error('VALIDATION_FAILED', 'Client may not supply ' . $forbidden . '.', 422);
                }
            }

            $token = trim((string) ($body['pricingContextToken'] ?? $request->getHeaderLine('X-Pricing-Context-Token')));
            $context = $this->actorContext->fromAuthenticatedUser($user, $token !== '' ? $token : null);

            $variantId = trim((string) ($body['variantId'] ?? $body['productVariantId'] ?? ''));
            if ($variantId === '') {
                return $this->responder->error('VALIDATION_FAILED', 'variantId is required.', 422);
            }

            $quantity = (int) ($body['quantity'] ?? 0);
            $length = (int) ($body['length'] ?? 0);
            $clientPrice = isset($body['unitPrice']) ? (string) $body['unitPrice'] : null;

            $result = $this->prepare->prepare($variantId, $context, $quantity, $length, $clientPrice);

            return $this->responder->success($result);
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
