<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicCart;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Commerce\Application\Service\AddCartItemService;
use TriCab\Core\Commerce\Domain\Exception\CartCurrencyMismatch;
use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;

/** POST /api/v1/cart/items */
final class PostCartItemAction
{
    private const FORBIDDEN_BODY_KEYS = [
        'unitPrice',
        'lineTotal',
        'marginPercent',
        'dbco',
        'priceListId',
        'baseCost',
        'customerId',
    ];

    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly AddCartItemService $addItem,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $user = $this->requestUser->fromRequest($request);
            if (!$user instanceof AuthenticatedUser) {
                return $this->responder->error(CustomerSellPriceStatus::LOGIN_REQUIRED, 'Sign in to add items to cart.', 401);
            }

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }

            foreach (self::FORBIDDEN_BODY_KEYS as $forbidden) {
                if (array_key_exists($forbidden, $body)) {
                    return $this->responder->error('VALIDATION_FAILED', 'Client may not supply ' . $forbidden . '.', 422);
                }
            }

            $token = trim((string) ($body['pricingContextToken'] ?? $request->getHeaderLine('X-Pricing-Context-Token')));
            $variantId = trim((string) ($body['variantId'] ?? $body['productVariantId'] ?? ''));
            if ($variantId === '') {
                return $this->responder->error('VALIDATION_FAILED', 'variantId is required.', 422);
            }

            $quantity = (int) ($body['quantity'] ?? 0);
            $length = $body['length'] ?? null;
            if ($length === null || $length === '') {
                return $this->responder->error('VALIDATION_FAILED', 'length is required.', 422);
            }
            $purchaseOptionKey = isset($body['purchaseOptionKey']) ? trim((string) $body['purchaseOptionKey']) : null;
            if ($purchaseOptionKey === '') {
                $purchaseOptionKey = null;
            }

            // unitPrice may still arrive under alternate keys — ignore for authority; flag ignored if present as claim
            $clientPrice = null;
            if (isset($body['claimedUnitPrice'])) {
                $clientPrice = (string) $body['claimedUnitPrice'];
            }

            $result = $this->addItem->execute(
                $user,
                $variantId,
                $quantity,
                is_numeric($length) || is_string($length) ? $length : (string) $length,
                $token !== '' ? $token : null,
                $purchaseOptionKey,
                $clientPrice,
            );

            if (($result['status'] ?? null) === CustomerSellPriceStatus::LOGIN_REQUIRED) {
                return $this->responder->error(CustomerSellPriceStatus::LOGIN_REQUIRED, (string) ($result['displayMessage'] ?? 'Sign in required.'), 401);
            }
            if (($result['accepted'] ?? true) === false) {
                return $this->responder->error((string) ($result['status'] ?? 'REVIEW'), (string) ($result['displayMessage'] ?? 'Unable to add item.'), 422);
            }

            return $this->responder->success($result);
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (CartCurrencyMismatch $e) {
            return $this->responder->error('CART_CURRENCY_MISMATCH', $e->getMessage(), 409);
        } catch (InvalidCartValue $e) {
            return $this->responder->error('VALIDATION_FAILED', $e->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
