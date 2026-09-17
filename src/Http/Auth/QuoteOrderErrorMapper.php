<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\QuoteOrder\Application\Exception\OrderNotFound;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteNotFound;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderAccessDenied;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderSourceUnavailable;

final class QuoteOrderErrorMapper
{
    public function __construct(private readonly JsonResponder $responder)
    {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof HttpRequestValidationFailed) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof QuoteOrderAccessDenied) {
            return $this->responder->error('QUOTE_ORDER_ACCESS_DENIED', $exception->getMessage(), 403);
        }

        if ($exception instanceof QuoteNotFound) {
            return $this->responder->error('QUOTE_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof OrderNotFound) {
            return $this->responder->error('ORDER_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof QuoteOrderSourceUnavailable) {
            return $this->responder->error('QUOTE_ORDER_SOURCE_UNAVAILABLE', $exception->getMessage(), 503);
        }

        if ($exception instanceof InvalidArgumentException || $exception instanceof DomainException) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        }

        if ($exception instanceof RuntimeException && str_contains($exception->getMessage(), 'Authenticated user')) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        return $this->responder->error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
    }
}
