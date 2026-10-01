<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Identity;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine;

/**
 * Direct manager via Microsoft Graph, then the TriCab staff directory.
 *
 * Uses the same client-credentials application as the rest of the API.
 * A Graph person who is not an active TriCab staff user is not an approver.
 * Lookup failure returns null so the credit request can use an explicit
 * coordinator fallback. It does not invent a person.
 */
final class GraphCustomerContactReportReportingLine implements CustomerContactReportReportingLine
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const USERS_URL = 'https://graph.microsoft.com/v1.0/users';

    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly CustomerContactReportAssigneeDirectory $staff,
    ) {
    }

    public function isAvailable(): bool
    {
        return trim($this->tenantId) !== ''
            && trim($this->clientId) !== ''
            && trim($this->clientSecret) !== '';
    }

    public function managerOf(string $staffLogin): ?ResolvedCustomerContactAssignee
    {
        $login = strtolower(trim($staffLogin));
        if (!$this->isAvailable() || $login === '' || !str_contains($login, '@')) {
            return null;
        }

        try {
            $userId = $this->graphUserId($login);
            if ($userId === null) {
                return null;
            }
            $manager = $this->managerIdentity($userId);
            if ($manager === null) {
                return null;
            }
            foreach ($manager as $candidate) {
                $person = $this->staff->findActiveStaffByLogin($candidate);
                if ($person !== null) {
                    return $person;
                }
            }
        } catch (Throwable $failure) {
            error_log((json_encode([
                'event' => 'customer_contact_reporting_line_failed',
                'exception' => $failure::class,
            ], JSON_UNESCAPED_SLASHES)) ?: '{"event":"customer_contact_reporting_line_failed"}');
        }

        return null;
    }

    private function graphUserId(string $login): ?string
    {
        $quoted = str_replace("'", "''", $login);
        $query = http_build_query(
            [
                '$filter' => "mail eq '" . $quoted . "' or userPrincipalName eq '" . $quoted . "'",
                '$select' => 'id,accountEnabled,mail,userPrincipalName',
                '$top' => '5',
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
        $decoded = $this->getJson(self::USERS_URL . '?' . $query);
        if ($decoded === null) {
            return null;
        }
        $rows = $decoded['value'] ?? null;
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['accountEnabled'] ?? true) === false) {
                continue;
            }
            $id = $row['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function managerIdentity(string $userId): ?array
    {
        $url = self::USERS_URL . '/' . rawurlencode($userId) . '/manager?' . http_build_query(
            ['$select' => 'mail,userPrincipalName,accountEnabled'],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
        $decoded = $this->getJson($url);
        if ($decoded === null || ($decoded['accountEnabled'] ?? true) === false) {
            return null;
        }
        $candidates = [];
        foreach (['mail', 'userPrincipalName'] as $key) {
            $value = $decoded[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $email = strtolower(trim($value));
            if ($email !== '' && str_contains($email, '@') && !in_array($email, $candidates, true)) {
                $candidates[] = $email;
            }
        }

        return $candidates === [] ? null : $candidates;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getJson(string $url): ?array
    {
        $response = $this->http->getJson($url, ['Authorization' => 'Bearer ' . $this->accessToken()]);
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new \RuntimeException('Graph directory lookup failed.');
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \RuntimeException('Graph directory lookup failed.');
        }
        if (!is_array($decoded)) {
            return null;
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    private function accessToken(): string
    {
        $now = time();
        if (
            $this->cachedAccessToken !== null
            && $this->cachedAccessToken !== ''
            && $now < ($this->cachedAccessTokenExpiresAt - 60)
        ) {
            return $this->cachedAccessToken;
        }

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
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new \RuntimeException('Graph directory lookup failed.');
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \RuntimeException('Graph directory lookup failed.');
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Graph directory lookup failed.');
        }
        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Graph directory lookup failed.');
        }
        $expiresIn = $decoded['expires_in'] ?? 3600;
        $ttl = is_int($expiresIn) || (is_string($expiresIn) && is_numeric($expiresIn)) ? (int) $expiresIn : 3600;
        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + max(60, $ttl);

        return $token;
    }
}
