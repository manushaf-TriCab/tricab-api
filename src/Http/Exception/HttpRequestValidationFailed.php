<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Exception;

use RuntimeException;

/**
 * HTTP-layer request validation failure with a stable client error code.
 */
final class HttpRequestValidationFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message = 'The request is invalid.'): self
    {
        return new self($message, 'REQUEST_INVALID', 400);
    }

    public static function unprocessable(string $errorCode, string $message): self
    {
        return new self($message, $errorCode, 422);
    }
}
