<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Directory;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Npr\Contract\NprRequesterDirectory;
use TriCab\Core\Npr\Domain\NprRequesterContact;

/**
 * The requester's office location and phone from Microsoft Graph (the fields the
 * Tel Directory shows), with the API's `GRAPH_*` client-credentials registration.
 * Mobile is preferred over the first business phone. Any failure is logged and
 * answers null: a draft still opens, with the fields left for the person.
 */
final class GraphNprRequesterDirectory implements NprRequesterDirectory
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const USERS_URL = 'https://graph.microsoft.com/v1.0/users';

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function contactFor(string $email): ?NprRequesterContact
    {
        $login = strtolower(trim($email));
        if (trim($this->tenantId) === '' || trim($this->clientId) === '' || trim($this->clientSecret) === '' || !str_contains($login, '@')) {
            return null;
        }

        try {
            return $this->lookup($login);
        } catch (Throwable $failure) {
            error_log((json_encode([
                'event' => 'npr_requester_directory_failed',
                'exception' => $failure::class,
            ], JSON_UNESCAPED_SLASHES)) ?: '{"event":"npr_requester_directory_failed"}');

            return null;
        }
    }

    private function lookup(string $login): ?NprRequesterContact
    {
        $quoted = str_replace("'", "''", $login);
        $query = http_build_query(
            [
                '$filter' => "mail eq '" . $quoted . "' or userPrincipalName eq '" . $quoted . "'",
                '$select' => 'accountEnabled,officeLocation,mobilePhone,businessPhones',
                '$top' => '5',
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
        $response = $this->http->getJson(self::USERS_URL . '?' . $query, ['Authorization' => 'Bearer ' . $this->accessToken()]);
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new \RuntimeException('Graph directory lookup failed.');
        }
        $rows = self::decode($response['body'])['value'] ?? null;
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['accountEnabled'] ?? true) === false) {
                continue;
            }
            $phones = is_array($row['businessPhones'] ?? null) ? $row['businessPhones'] : [];

            return new NprRequesterContact(
                self::text($row['officeLocation'] ?? null),
                self::text($row['mobilePhone'] ?? null) ?? self::text($phones[0] ?? null),
            );
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<mixed> */
    private static function decode(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \RuntimeException('Graph directory lookup failed.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function accessToken(): string
    {
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
            throw new \RuntimeException('Graph token request failed.');
        }
        $token = self::decode($response['body'])['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Graph token request failed.');
        }

        return $token;
    }
}
