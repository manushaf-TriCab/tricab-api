<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

final readonly class ExchangeTrustedDeviceSessionRequest
{
    public function __construct(
        public string $publicId,
        public string $credential,
        public string $installationId,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $publicId = $body['publicId'] ?? null;
        if (!is_string($publicId) || trim($publicId) === '') {
            throw HttpRequestValidationFailed::invalid('publicId is required.');
        }

        $credential = $body['credential'] ?? null;
        if (!is_string($credential) || $credential === '') {
            throw HttpRequestValidationFailed::invalid('credential is required.');
        }

        $installationId = $body['installationId'] ?? null;
        if (!is_string($installationId) || trim($installationId) === '') {
            throw HttpRequestValidationFailed::invalid('installationId is required.');
        }

        return new self(
            publicId: trim($publicId),
            credential: $credential,
            installationId: trim($installationId),
        );
    }
}
