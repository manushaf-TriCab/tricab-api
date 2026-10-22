<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Core\Identity\Application\Handler\StartActAsHandler;

/**
 * Validated body for POST /api/v1/admin/act-as.
 */
final readonly class StartActAsRequest
{
    private function __construct(
        public string $userPublicId,
        public string $reason,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $userPublicId = $body['userPublicId'] ?? null;
        if (!is_string($userPublicId) || trim($userPublicId) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'userPublicId is required.');
        }

        $reason = $body['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'reason is required.');
        }

        if (mb_strlen(trim($reason)) > StartActAsHandler::MAX_REASON_LENGTH) {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                sprintf('reason must be at most %d characters.', StartActAsHandler::MAX_REASON_LENGTH),
            );
        }

        return new self(userPublicId: trim($userPublicId), reason: trim($reason));
    }
}
