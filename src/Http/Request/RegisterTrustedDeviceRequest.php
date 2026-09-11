<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

final readonly class RegisterTrustedDeviceRequest
{
    public function __construct(
        public string $installationId,
        public string $platform,
        public ?string $deviceName,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $installationId = $body['installationId'] ?? null;
        if (!is_string($installationId) || trim($installationId) === '') {
            throw HttpRequestValidationFailed::invalid('installationId is required.');
        }

        $platform = $body['platform'] ?? null;
        if (!is_string($platform) || trim($platform) === '') {
            throw HttpRequestValidationFailed::invalid('platform is required.');
        }

        $deviceName = $body['deviceName'] ?? null;
        if ($deviceName !== null && !is_string($deviceName)) {
            throw HttpRequestValidationFailed::invalid('deviceName must be a string.');
        }

        return new self(
            installationId: trim($installationId),
            platform: trim($platform),
            deviceName: is_string($deviceName) ? trim($deviceName) : null,
        );
    }
}
