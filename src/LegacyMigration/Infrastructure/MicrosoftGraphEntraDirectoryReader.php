<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Infrastructure;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\LegacyMigration\Contract\EntraDirectoryReader;

/**
 * Read-only Microsoft Graph directory adapter. Never POST/PATCH/DELETE users.
 */
final class MicrosoftGraphEntraDirectoryReader implements EntraDirectoryReader
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const USERS_URL = 'https://graph.microsoft.com/v1.0/users?$select=id,userPrincipalName,mail,displayName,accountEnabled&$top=999';

    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenExpiresAt = 0;

    private int $readOperations = 0;

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

    public function listStaffDirectoryUsers(string $expectedTenantId): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $token = $this->acquireAccessToken();
        $users = [];
        $url = self::USERS_URL;

        while ($url !== '') {
            ++$this->readOperations;
            try {
                $response = $this->http->getJson($url, ['Authorization' => 'Bearer ' . $token]);
            } catch (Throwable) {
                throw new \RuntimeException('Microsoft Graph directory read failed.');
            }

            if ($response['status'] < 200 || $response['status'] > 299) {
                $code = $this->parseGraphErrorCode($response['body']);
                $suffix = $code !== null ? ' (' . $code . ')' : '';
                throw new \RuntimeException('Microsoft Graph directory read returned HTTP ' . $response['status'] . $suffix . '.');
            }

            try {
                /** @var mixed $decoded */
                $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new \RuntimeException('Microsoft Graph directory response was not valid JSON.');
            }

            if (!is_array($decoded)) {
                break;
            }

            foreach (is_array($decoded['value'] ?? null) ? $decoded['value'] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $oid = trim((string) ($row['id'] ?? ''));
                if ($oid === '') {
                    continue;
                }
                $users[] = [
                    'objectId' => $oid,
                    'userPrincipalName' => strtolower(trim((string) ($row['userPrincipalName'] ?? ''))),
                    'mail' => strtolower(trim((string) ($row['mail'] ?? ''))),
                    'displayName' => trim((string) ($row['displayName'] ?? '')),
                    'accountEnabled' => ($row['accountEnabled'] ?? false) === true,
                    'tenantId' => $expectedTenantId,
                ];
            }

            $next = $decoded['@odata.nextLink'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : '';
        }

        return $users;
    }

    public function auditSummary(): array
    {
        return [
            'readOperations' => $this->readOperations,
            'writeOperations' => 0,
        ];
    }

    public function clearTokenCache(): void
    {
        $this->cachedAccessToken = null;
        $this->cachedAccessTokenExpiresAt = 0;
    }

    public function verifyHealth(string $expectedTenantId): array
    {
        $this->clearTokenCache();
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'authenticated' => false,
                'directoryRead' => false,
                'httpStatus' => null,
                'graphErrorCode' => 'NOT_CONFIGURED',
                'writeOperations' => 0,
            ];
        }

        try {
            $token = $this->acquireAccessToken();
            $roles = $this->tokenApplicationRoles($token);
            ++$this->readOperations;
            $url = 'https://graph.microsoft.com/v1.0/users?$select=id,userPrincipalName,mail,displayName,accountEnabled&$top=1';
            $response = $this->http->getJson($url, ['Authorization' => 'Bearer ' . $token]);
            $status = $response['status'];
            if ($status >= 200 && $status <= 299) {
                return [
                    'configured' => true,
                    'authenticated' => true,
                    'directoryRead' => true,
                    'httpStatus' => $status,
                    'graphErrorCode' => null,
                    'writeOperations' => 0,
                    'tenantId' => $expectedTenantId,
                    'clientId' => $this->clientId,
                    'tokenRoles' => $roles,
                    'hasUserReadAll' => in_array('User.Read.All', $roles, true),
                ];
            }

            return [
                'configured' => true,
                'authenticated' => true,
                'directoryRead' => false,
                'httpStatus' => $status,
                'graphErrorCode' => $this->parseGraphErrorCode($response['body']),
                'writeOperations' => 0,
                'tenantId' => $expectedTenantId,
                'clientId' => $this->clientId,
                'tokenRoles' => $roles,
                'hasUserReadAll' => in_array('User.Read.All', $roles, true),
            ];
        } catch (Throwable) {
            return [
                'configured' => true,
                'authenticated' => false,
                'directoryRead' => false,
                'httpStatus' => null,
                'graphErrorCode' => 'TRANSPORT_FAILURE',
                'writeOperations' => 0,
                'tenantId' => $expectedTenantId,
                'clientId' => $this->clientId,
            ];
        }
    }

    private function parseGraphErrorCode(string $body): ?string
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }
            $error = $decoded['error'] ?? ($decoded['odata.error']['code'] ?? null);
            if (is_array($error)) {
                return is_string($error['code'] ?? null) ? $error['code'] : null;
            }

            return is_string($error) ? $error : null;
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Inspects JWT application roles only. Never logs or returns the token.
     *
     * @return list<string>
     */
    private function tokenApplicationRoles(string $accessToken): array
    {
        $parts = explode('.', $accessToken);
        if (!isset($parts[1]) || $parts[1] === '') {
            return [];
        }
        $payload = $parts[1];
        $pad = strlen($payload) % 4;
        if ($pad !== 0) {
            $payload .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($payload, '-_', '+/'), true);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded) || !is_array($decoded['roles'] ?? null)) {
            return [];
        }
        $roles = [];
        foreach ($decoded['roles'] as $role) {
            if (is_string($role) && $role !== '') {
                $roles[] = $role;
            }
        }

        return $roles;
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
            throw new \RuntimeException('Microsoft Graph token acquisition failed.');
        }

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new \RuntimeException('Microsoft Graph token acquisition returned HTTP ' . $response['status'] . '.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \RuntimeException('Microsoft Graph token response was not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Microsoft Graph token response was empty.');
        }

        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Microsoft Graph token response missing access_token.');
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
