<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PriceLookup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupForbidden;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupProductNotFound;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupUnavailable;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Application\Service\SearchPriceLookupService;

final class SearchPriceLookupAction
{
    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly SearchPriceLookupService $search,
        private readonly JsonResponder $responder,
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

            $params = $request->getQueryParams();
            $q = isset($params['q']) && is_string($params['q']) ? $params['q'] : '';
            $company = isset($params['company']) && is_string($params['company']) ? $params['company'] : '';

            $result = $this->search->execute($q, $company, $user->permissions);

            return $this->responder->success(PriceLookupDtoSerializer::search($result));
        } catch (PriceLookupValidationException $e) {
            return $this->responder->error($e->errorCode, $e->getMessage(), 422);
        } catch (PriceLookupForbidden $e) {
            return $this->responder->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (PriceLookupProductNotFound $e) {
            return $this->responder->error('PRODUCT_NOT_FOUND', $e->getMessage(), 404);
        } catch (Throwable $exception) {
            error_log('[price-lookup] search unexpected: ' . $exception->getMessage());

            return $this->responder->error('PRICE_LOOKUP_UNAVAILABLE', 'Unable to search prices right now.', 503);
        }
    }
}
