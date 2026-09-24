<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PriceLookup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupProductNotFound;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupUnavailable;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Application\Service\QuotePriceLookupService;

final class QuotePriceLookupAction
{
    public function __construct(
        private readonly ResolveRequestAuthenticatedUser $requestUser,
        private readonly QuotePriceLookupService $quote,
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
            unset($user);

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $raw = (string) $request->getBody();
                $decoded = $raw !== '' ? json_decode($raw, true) : null;
                $body = is_array($decoded) ? $decoded : [];
            }

            $productCode = isset($body['productCode']) && is_string($body['productCode']) ? $body['productCode'] : '';
            $company = isset($body['company']) && is_string($body['company']) ? $body['company'] : '';
            $currency = isset($body['currency']) && is_string($body['currency']) ? $body['currency'] : '';
            $metric = isset($body['metric']) && is_string($body['metric']) ? $body['metric'] : '';
            $length = isset($body['length']) && (is_string($body['length']) || is_numeric($body['length']))
                ? (string) $body['length']
                : null;
            $marginPercent = isset($body['marginPercent']) && (is_string($body['marginPercent']) || is_numeric($body['marginPercent']))
                ? (string) $body['marginPercent']
                : null;

            $result = $this->quote->execute(
                $productCode,
                $company,
                $currency,
                $metric,
                $length,
                $marginPercent,
            );

            return $this->responder->success(PriceLookupDtoSerializer::quote($result));
        } catch (PriceLookupValidationException $e) {
            return $this->responder->error($e->errorCode, $e->getMessage(), 422);
        } catch (PriceLookupProductNotFound $e) {
            return $this->responder->error('PRODUCT_NOT_FOUND', $e->getMessage(), 404);
        } catch (PriceLookupUnavailable $e) {
            return $this->responder->error($e->errorCode, $e->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log('[price-lookup] quote unexpected: ' . $exception->getMessage());

            return $this->responder->error('PRICE_LOOKUP_UNAVAILABLE', 'Unable to calculate price right now.', 503);
        }
    }
}
