<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicCart;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Commerce\Application\Service\UpdateCartItemService;
use TriCab\Core\Commerce\Domain\Exception\CartConcurrencyConflict;
use TriCab\Core\Commerce\Domain\Exception\CartNotFound;
use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;

/** PATCH /api/v1/cart/items/{lineId} */
final class PatchCartItemAction
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
        private readonly UpdateCartItemService $updateItem,
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
                return $this->responder->error(CustomerSellPriceStatus::LOGIN_REQUIRED, 'Sign in to update cart.', 401);
            }

            $lineId = RouteParams::require($request, 'lineId');

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }
            foreach (self::FORBIDDEN_BODY_KEYS as $forbidden) {
                if (array_key_exists($forbidden, $body)) {
                    return $this->responder->error('VALIDATION_FAILED', 'Client may not supply ' . $forbidden . '.', 422);
                }
            }

            $rowVersion = trim((string) ($body['rowVersion'] ?? ''));
            if ($rowVersion === '') {
                return $this->responder->error('VALIDATION_FAILED', 'rowVersion is required.', 422);
            }

            $quantity = array_key_exists('quantity', $body) ? (int) $body['quantity'] : null;
            $length = array_key_exists('length', $body) ? $body['length'] : null;
            $token = trim((string) ($body['pricingContextToken'] ?? $request->getHeaderLine('X-Pricing-Context-Token')));

            $result = $this->updateItem->execute(
                $user,
                $lineId,
                $quantity,
                $length,
                $rowVersion,
                $token !== '' ? $token : null,
            );

            return $this->responder->success($result);
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (CartConcurrencyConflict $e) {
            return $this->responder->error('CONCURRENCY_CONFLICT', $e->getMessage(), 409);
        } catch (CartNotFound $e) {
            return $this->responder->error('NOT_FOUND', $e->getMessage(), 404);
        } catch (InvalidCartValue $e) {
            return $this->responder->error('VALIDATION_FAILED', $e->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
