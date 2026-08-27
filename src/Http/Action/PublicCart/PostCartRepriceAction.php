<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicCart;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Commerce\Application\Service\RepriceCartService;
use TriCab\Core\Commerce\Domain\Exception\CartConcurrencyConflict;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;

/** POST /api/v1/cart/reprice */
final class PostCartRepriceAction
{
    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly RepriceCartService $reprice,
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
                return $this->responder->error(CustomerSellPriceStatus::LOGIN_REQUIRED, 'Sign in to reprice cart.', 401);
            }

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }
            foreach (['unitPrice', 'lineTotal', 'marginPercent', 'dbco', 'priceListId', 'baseCost', 'customerId'] as $forbidden) {
                if (array_key_exists($forbidden, $body)) {
                    return $this->responder->error('VALIDATION_FAILED', 'Client may not supply ' . $forbidden . '.', 422);
                }
            }

            $commit = filter_var($body['commit'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $token = trim((string) ($body['pricingContextToken'] ?? $request->getHeaderLine('X-Pricing-Context-Token')));
            $previousCustomer = isset($body['previousCustomerId']) ? trim((string) $body['previousCustomerId']) : null;

            $result = $this->reprice->execute(
                $user,
                $commit,
                $token !== '' ? $token : null,
                $previousCustomer !== '' ? $previousCustomer : null,
            );

            return $this->responder->success($result);
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (CartConcurrencyConflict $e) {
            return $this->responder->error('CONCURRENCY_CONFLICT', $e->getMessage(), 409);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
