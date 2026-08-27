<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\CustomerManagementDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Request\Admin\CustomerListQuery;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Service\ListCustomersService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;

/**
 * GET /api/v1/pricing/customers?search=
 * Staff customer search for pricing context (CUSTOMER.PRICE_AS — not CUSTOMER.READ).
 */
final class GetPricingCustomersAction
{
    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly ResolvePricingActorContextService $actorContext,
        private readonly ListCustomersService $listCustomers,
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
            if (!$this->actorContext->canPriceAs($user)) {
                return $this->responder->error('FORBIDDEN', 'Staff price-as-customer permission required.', 403);
            }

            $criteria = CustomerListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->listCustomers->execute($criteria);

            return $this->responder->success(CustomerManagementDtoSerializer::customerListResult($result));
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
