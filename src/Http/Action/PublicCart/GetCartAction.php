<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicCart;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Commerce\Application\Service\GetCartService;
use TriCab\Core\Commerce\Domain\Exception\CartAccessDenied;
use TriCab\Core\Commerce\Domain\Exception\CartNotFound;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;

/** GET /api/v1/cart */
final class GetCartAction
{
    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly GetCartService $getCart,
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
                return $this->responder->error(CustomerSellPriceStatus::LOGIN_REQUIRED, 'Sign in to view your cart.', 401);
            }

            $token = trim((string) ($request->getQueryParams()['pricingContextToken'] ?? $request->getHeaderLine('X-Pricing-Context-Token')));
            $result = $this->getCart->execute($user, $token !== '' ? $token : null);

            if (($result['status'] ?? null) === CustomerSellPriceStatus::LOGIN_REQUIRED) {
                return $this->responder->error(CustomerSellPriceStatus::LOGIN_REQUIRED, (string) ($result['displayMessage'] ?? 'Sign in required.'), 401);
            }

            return $this->responder->success($result);
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (CartAccessDenied $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (CartNotFound $e) {
            return $this->responder->error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
