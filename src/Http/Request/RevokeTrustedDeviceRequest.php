<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

final readonly class RevokeTrustedDeviceRequest
{
    public function __construct(
        public string $publicId,
        public ?string $installationId,
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

        $installationId = $body['installationId'] ?? null;
        if ($installationId !== null && !is_string($installationId)) {
            throw HttpRequestValidationFailed::invalid('installationId must be a string.');
        }

        return new self(
            publicId: trim($publicId),
            installationId: is_string($installationId) ? trim($installationId) : null,
        );
    }
}
