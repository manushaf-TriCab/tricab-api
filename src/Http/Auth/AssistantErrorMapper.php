<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;

final class AssistantErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof HttpRequestValidationFailed) {
            return $this->responder->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        if ($exception instanceof DatabaseUnavailable) {
            return $this->responder->error(
                'ASSISTANT_CATALOGUE_UNAVAILABLE',
                'Product information is temporarily unavailable.',
                503,
            );
        }

        return $this->responder->error(
            'ASSISTANT_UNAVAILABLE',
            'The product assistant is temporarily unavailable.',
            503,
        );
    }
}
