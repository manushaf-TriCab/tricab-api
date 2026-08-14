<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use JsonException;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

/**
 * Retrieves an Entra token from Azure's local managed-identity endpoint.
 */
final class ManagedIdentityFoundryAccessTokenProvider implements FoundryAccessTokenProvider
{
    private const RESOURCE = 'https://ai.azure.com';
    private const IMDS_ENDPOINT = 'http://169.254.169.254/metadata/identity/oauth2/token';
    private const CACHE_SKEW_SECONDS = 60;

    /** @var array<string, string|null> */
    private array $environment;

    private ?string $cachedToken = null;

    private int $cachedExpiresAt = 0;

    /**
     * @param array<string, string|null>|null $environment
     */
    public function __construct(
        private readonly JsonHttpClient $http,
        ?array $environment = null,
    ) {
        $this->environment = $environment ?? self::processEnvironment();
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

        [$endpoint, $headers, $apiVersion] = $this->tokenRequest();
        $url = self::appendQuery($endpoint, [
            'api-version' => $apiVersion,
            'resource' => self::RESOURCE,
        ]);

        try {
            $response = $this->http->getJson($url, $headers);
        } catch (\Throwable $exception) {
            throw new FoundryProviderUnavailable(
                'Managed identity token request failed.',
                previous: $exception,
            );
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new FoundryProviderUnavailable('Managed identity token request was rejected.');
        }

        try {
            $decoded = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoundryProviderUnavailable(
                'Managed identity token response was invalid.',
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new FoundryProviderUnavailable('Managed identity token response was invalid.');
        }

        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || trim($token) === '') {
            throw new FoundryProviderUnavailable('Managed identity token response did not contain a token.');
        }

        $this->cachedToken = $token;
        $this->cachedExpiresAt = self::parseExpiry($decoded['expires_on'] ?? null, $now);

        return $token;
    }

    /**
     * @return array{string, array<string, string>, string}
     */
    private function tokenRequest(): array
    {
        $identityEndpoint = trim($this->environment['IDENTITY_ENDPOINT'] ?? '');
        $identityHeader = trim($this->environment['IDENTITY_HEADER'] ?? '');

        if ($identityEndpoint !== '' || $identityHeader !== '') {
            if ($identityEndpoint === '' || $identityHeader === '') {
                throw new FoundryProviderUnavailable(
                    'Managed identity environment is incomplete.',
                );
            }
            self::assertLocalEndpoint($identityEndpoint);

            return [
                $identityEndpoint,
                ['X-IDENTITY-HEADER' => $identityHeader],
                '2019-08-01',
            ];
        }

        return [
            self::IMDS_ENDPOINT,
            ['Metadata' => 'true'],
            '2018-02-01',
        ];
    }

    /**
     * @param array<string, string> $query
     */
    private static function appendQuery(string $endpoint, array $query): string
    {
        return $endpoint
            . (str_contains($endpoint, '?') ? '&' : '?')
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function assertLocalEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts)) {
            throw new FoundryProviderUnavailable('Managed identity endpoint is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isLoopback = $host === 'localhost'
            || $host === '::1'
            || str_starts_with($host, '127.');
        $isLinkLocal = str_starts_with($host, '169.254.');

        if (
            $scheme !== 'http'
            || (!$isLoopback && !$isLinkLocal)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new FoundryProviderUnavailable(
                'Managed identity endpoint must be a local HTTP address.',
            );
        }
    }

    private static function parseExpiry(mixed $value, int $now): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (ctype_digit($value)) {
                return (int) $value;
            }

            $parsed = strtotime($value);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return $now + 300;
    }

    /**
     * @return array<string, string|null>
     */
    private static function processEnvironment(): array
    {
        return getenv();
    }
}
