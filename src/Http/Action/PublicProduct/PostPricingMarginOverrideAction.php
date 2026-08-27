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
use TriCab\Core\Product\Application\Pricing\InvalidMarginOverrideException;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;

/**
 * POST /api/v1/pricing/margin-override
 * Authorized staff margin override for an existing price-as context token.
 */
final class PostPricingMarginOverrideAction
{
    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly ResolvePricingActorContextService $actorContext,
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
                return $this->responder->error('UNAUTHENTICATED', 'Authentication required.', 401);
            }

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }

            foreach (['dbco', 'priceListId', 'baseCost', 'unitPrice', 'customerId'] as $forbidden) {
                if (array_key_exists($forbidden, $body)) {
                    return $this->responder->error('VALIDATION_FAILED', 'Client may not supply ' . $forbidden . '.', 422);
                }
            }

            $token = trim((string) ($body['pricingContextToken'] ?? $request->getHeaderLine('X-Pricing-Context-Token')));
            if ($token === '') {
                return $this->responder->error('VALIDATION_FAILED', 'pricingContextToken is required.', 422);
            }

            if (array_key_exists('clear', $body) && $body['clear'] === true) {
                return $this->responder->success($this->actorContext->clearOverride($user, $token));
            }

            if (!array_key_exists('margin', $body) && !array_key_exists('overrideMargin', $body)) {
                return $this->responder->error('VALIDATION_FAILED', 'margin is required.', 422);
            }

            $margin = $body['margin'] ?? $body['overrideMargin'];
            $result = $this->actorContext->applyMarginOverride($user, $token, is_scalar($margin) ? $margin : '');

            return $this->responder->success($result);
        } catch (InvalidMarginOverrideException $e) {
            return $this->responder->error('INVALID_MARGIN_OVERRIDE', $e->getMessage(), 422);
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
