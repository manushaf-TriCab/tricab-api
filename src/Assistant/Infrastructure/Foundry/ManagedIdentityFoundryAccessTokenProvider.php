<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use TriCab\Api\Infrastructure\Authentication\ManagedIdentityTokenClient;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentityTokenException;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

/**
 * Retrieves an Entra token from Azure's local managed-identity endpoint.
 */
final class ManagedIdentityFoundryAccessTokenProvider implements FoundryAccessTokenProvider
{
    private const RESOURCE = 'https://ai.azure.com';
    private const CACHE_SKEW_SECONDS = 60;

    private readonly ManagedIdentityTokenClient $client;

    private ?string $cachedToken = null;

    private int $cachedExpiresAt = 0;

    /**
     * @param array<string, string|null>|null $environment
     */
    public function __construct(
        JsonHttpClient $http,
        ?array $environment = null,
        ?ManagedIdentityTokenClient $client = null,
    ) {
        $this->client = $client ?? new ManagedIdentityTokenClient($http, $environment);
    }

    public function accessToken(): string
    {
        $now = time();
        if (
            $this->cachedToken !== null
            && $this->cachedExpiresAt > $now + self::CACHE_SKEW_SECONDS
        ) {
            return $this->cachedToken;
        }

        try {
            $acquired = $this->client->fetch(self::RESOURCE);
        } catch (ManagedIdentityTokenException $exception) {
            throw new FoundryProviderUnavailable(
                $exception->getMessage(),
                0,
                $exception,
            );
        }

        $this->cachedToken = $acquired->token;
        $this->cachedExpiresAt = $acquired->expiresAt;

        return $this->cachedToken;
    }
}
