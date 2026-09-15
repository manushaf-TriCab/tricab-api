<?php

declare(strict_types=1);

namespace TriCab\Api\TelDirectory\Infrastructure;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\TelDirectory\Application\Exception\TelDirectoryUnavailable;
use TriCab\Core\TelDirectory\Contract\TelDirectoryGraphReader;

/**
 * Server-side Microsoft Graph adapter for Tel Directory.
 * Reuses the same client-credentials pattern as other Graph adapters.
 * Never exposes tokens or client secrets.
 */
final class MicrosoftGraphTelDirectoryReader implements TelDirectoryGraphReader
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const USERS_BASE_URL = 'https://graph.microsoft.com/v1.0/users';

    /**
     * Legacy parity select (CustomAttribute1 is fetched but never returned to mobile).
     * accountEnabled filter matches WordPress MSGRAPH_getAllActiveUsersForDir().
     */
    private const USERS_SELECT = 'displayName,jobTitle,mail,postalCode,mobilePhone,businessPhones,officeLocation,accountEnabled';

    private const USERS_FILTER = 'accountEnabled eq true';

    private const USERS_TOP = '999';

    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->tenantId) !== ''
            && trim($this->clientId) !== ''
            && trim($this->clientSecret) !== '';
    }

    public function listActiveDirectoryUsers(): array
    {
        if (!$this->isConfigured()) {
            throw TelDirectoryUnavailable::notConfigured();
        }

        $token = $this->acquireAccessToken();
        $users = [];
        // Initial request is RFC3986-encoded. Graph @odata.nextLink values are used as-is
        // (already absolute + encoded by Graph — never rebuild/double-encode them).
        $url = self::buildInitialUsersUrl();

        while ($url !== '') {
            try {
                $response = $this->http->getJson($url, ['Authorization' => 'Bearer ' . $token]);
            } catch (Throwable) {
                throw TelDirectoryUnavailable::graphFailure('(transport)');
            }

            if ($response['status'] < 200 || $response['status'] > 299) {
                throw TelDirectoryUnavailable::graphFailure('(HTTP ' . $response['status'] . ')');
            }

            try {
                /** @var mixed $decoded */
                $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw TelDirectoryUnavailable::graphFailure('(invalid JSON)');
            }

            if (!is_array($decoded)) {
                break;
            }

            foreach (is_array($decoded['value'] ?? null) ? $decoded['value'] : [] as $row) {
                if (is_array($row)) {
                    $users[] = $row;
                }
            }

            $next = $decoded['@odata.nextLink'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : '';
        }

        return $users;
    }

    /**
     * Builds the first Graph users page URL with OData query parameters encoded per RFC3986.
     * Exposed for unit tests that assert encoding without calling Graph.
     */
    public static function buildInitialUsersUrl(): string
    {
        $query = http_build_query(
            [
                '$filter' => self::USERS_FILTER,
                '$select' => self::USERS_SELECT,
                '$top' => self::USERS_TOP,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return self::USERS_BASE_URL . '?' . $query;
    }

    public function clearTokenCache(): void
    {
        $this->cachedAccessToken = null;
        $this->cachedAccessTokenExpiresAt = 0;
    }

    private function acquireAccessToken(): string
    {
        $now = time();
        if (
            $this->cachedAccessToken !== null
            && $this->cachedAccessToken !== ''
            && $now < ($this->cachedAccessTokenExpiresAt - 60)
        ) {
            return $this->cachedAccessToken;
        }

        try {
            $response = $this->http->postForm(
                sprintf(self::TOKEN_URL_TEMPLATE, rawurlencode($this->tenantId)),
                [],
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => self::GRAPH_SCOPE,
                    'grant_type' => 'client_credentials',
                ],
            );
        } catch (Throwable) {
            throw TelDirectoryUnavailable::graphFailure('(token transport)');
        }

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw TelDirectoryUnavailable::graphFailure('(token HTTP ' . $response['status'] . ')');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TelDirectoryUnavailable::graphFailure('(token JSON)');
        }

        if (!is_array($decoded)) {
            throw TelDirectoryUnavailable::graphFailure('(token empty)');
        }

        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw TelDirectoryUnavailable::graphFailure('(missing access_token)');
        }

        $expiresIn = $decoded['expires_in'] ?? 3600;
        $ttl = is_int($expiresIn) || (is_string($expiresIn) && is_numeric($expiresIn))
            ? (int) $expiresIn
            : 3600;

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + max(60, $ttl);

        return $token;
    }
}
