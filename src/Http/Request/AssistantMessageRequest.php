<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

final readonly class AssistantMessageRequest
{
    public function __construct(
        public string $message,
        public ?string $searchQuery,
        public ?string $regionCode,
        public ?string $conversationId,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $message = self::requiredString($body, 'message', 2000);
        $searchQuery = self::optionalString($body, 'searchQuery', 200);
        $regionCode = self::optionalString($body, 'regionCode', 32);
        $conversationId = self::optionalString($body, 'conversationId', 36);

        if (
            $conversationId !== null
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $conversationId) !== 1
        ) {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                'conversationId must be a valid UUID.',
            );
        }

        if ($searchQuery !== null && mb_strlen($searchQuery) < 2) {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                'searchQuery must contain at least 2 characters.',
            );
        }

        return new self($message, $searchQuery, $regionCode, $conversationId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function requiredString(array $body, string $key, int $maxLength): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                sprintf('%s is required.', $key),
            );
        }

        $trimmed = trim($value);
        if (mb_strlen($trimmed) > $maxLength) {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                sprintf('%s must not exceed %d characters.', $key, $maxLength),
            );
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalString(array $body, string $key, int $maxLength): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }

        $value = $body[$key];
        if (!is_string($value)) {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                sprintf('%s must be a string.', $key),
            );
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                sprintf('%s must not exceed %d characters.', $key, $maxLength),
            );
        }

        return $trimmed;
    }
}
