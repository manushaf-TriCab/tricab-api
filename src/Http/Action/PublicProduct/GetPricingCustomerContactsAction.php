<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\CustomerManagementDtoSerializer;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Request\Admin\ContactListQuery;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Service\ListCustomerContactsService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\PricingActorAuthorizationException;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;

/**
 * GET /api/v1/pricing/customers/{customerPublicId}/contacts
 * Staff contact lookup for pricing context selection.
 */
final class GetPricingCustomerContactsAction
{
    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly ResolvePricingActorContextService $actorContext,
        private readonly ListCustomerContactsService $listCustomerContacts,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
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

            $customerPublicId = RouteParams::require($request, 'customerPublicId');
            $criteria = ContactListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->listCustomerContacts->execute($customerPublicId, $criteria);

            return $this->responder->success(CustomerManagementDtoSerializer::contactListResult($result));
        } catch (PricingActorAuthorizationException $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
