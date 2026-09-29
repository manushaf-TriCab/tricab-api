<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PriceLookup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\PriceLookup\Application\Service\ListPriceLookupCodesService;

/**
 * GET /api/v1/price-lookup/codes: every product code, for the page's type-ahead.
 * Codes only. The browser may reuse the list for a few minutes.
 */
final class ListPriceLookupCodesAction
{
    private const CACHE_CONTROL = 'private, max-age=300';

    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly ListPriceLookupCodesService $codes,
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

            $items = $this->codes->execute();

            return $this->responder->success(
                ['items' => $items, 'count' => count($items)],
                200,
                ['Cache-Control' => self::CACHE_CONTROL],
            );
        } catch (Throwable $exception) {
            error_log('[price-lookup] codes unexpected: ' . $exception->getMessage());

            return $this->responder->error('PRICE_LOOKUP_UNAVAILABLE', 'Unable to load product codes right now.', 503);
        }
    }
}
