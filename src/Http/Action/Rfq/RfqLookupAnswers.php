<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;

/**
 * What the header lookups (parity plan E1) share. Each is gated on
 * `RFQ.DRAFT.EDIT`: whoever can fill in a header can look up what goes in it.
 * A source that is down answers 503 `RFQ_LOOKUP_UNAVAILABLE`, and the editor
 * keeps its fields and says so.
 */
trait RfqLookupAnswers
{
    /** @param callable(): array<string, mixed> $read */
    private function answer(JsonResponder $responder, ServerRequestInterface $request, callable $read): ResponseInterface
    {
        if (!$request->getAttribute(AuthenticatedUserAttribute::KEY) instanceof AuthenticatedUser) {
            return $responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        try {
            return $responder->success($read());
        } catch (RfqLookupUnavailable $failure) {
            return $responder->error('RFQ_LOOKUP_UNAVAILABLE', $failure->getMessage(), 503);
        }
    }

    private static function queryParam(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getQueryParams()[$name] ?? '';

        return is_string($value) ? $value : '';
    }
}
