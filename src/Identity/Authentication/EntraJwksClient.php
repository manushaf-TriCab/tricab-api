<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use RuntimeException;

/**
 * Fetches Microsoft Entra OpenID JWKS for access-token signature validation.
 */
final class EntraJwksClient implements EntraJwksProvider
{
    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    public function fetch(string $tenantId): array
    {
        $url = sprintf(
            'https://login.microsoftonline.com/%s/discovery/v2.0/keys',
            rawurlencode($tenantId),
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new RuntimeException('Unable to download Microsoft Entra JWKS.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new RuntimeException('Microsoft Entra JWKS response is invalid.');
        }

        /** @var array{keys: list<array<string, mixed>>} $decoded */
        return $decoded;
    }
}
