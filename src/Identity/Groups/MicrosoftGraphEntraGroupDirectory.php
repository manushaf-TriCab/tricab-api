<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

use JsonException;
use RuntimeException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

/**
 * Read-only Microsoft Graph adapter for Entra mail groups. Needs the application permission
 * `GroupMember.Read.All` (tenant admin consent). Never POST/PATCH/DELETE.
 *
 * Until consent is granted Graph answers 403 (or 401); that is raised as {@see GraphPermissionNotGranted},
 * a typed outcome callers handle, not a crash. It uses the same `GRAPH_*` app registration as the other readers.
 */
final class MicrosoftGraphEntraGroupDirectory implements EntraGroupDirectory
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const SCOPE = 'https://graph.microsoft.com/.default';
    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const GROUP_FIELDS = 'id,displayName,mail,description,mailEnabled';
    private const MEMBER_FIELDS = 'id,displayName,mail,userPrincipalName,accountEnabled';

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->tenantId) !== '' && trim($this->clientId) !== '' && trim($this->clientSecret) !== '';
    }

    public function searchMailGroups(string $query, int $limit = 25): array
    {
        $term = trim(str_replace('"', '', $query));
        $url = self::GRAPH . '/groups?$select=' . self::GROUP_FIELDS . '&$top=' . max(1, min(100, $limit))
            . '&$filter=' . rawurlencode('mailEnabled eq true');
        if ($term !== '') {
            $url .= '&$search=' . rawurlencode('"displayName:' . $term . '"');
        }

        // $search with $filter is an advanced query: it needs this header and a count.
        $body = $this->get($url . '&$count=true', ['ConsistencyLevel' => 'eventual']);
        $groups = [];
        foreach ($this->rows($body['value'] ?? null) as $row) {
            $group = $this->group($row);
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return array_slice($groups, 0, $limit);
    }

    public function findGroup(string $entraGroupId): ?EntraGroup
    {
        try {
            $body = $this->get(self::GRAPH . '/groups/' . rawurlencode($entraGroupId) . '?$select=' . self::GROUP_FIELDS);
        } catch (EntraGroupNotFound) {
            return null;
        }

        return $this->group($body);
    }

    public function transitiveMembers(string $entraGroupId): array
    {
        // The cast keeps only users: nested groups are flattened into their users by `transitiveMembers`.
        $url = self::GRAPH . '/groups/' . rawurlencode($entraGroupId) . '/transitiveMembers/microsoft.graph.user?$select=' . self::MEMBER_FIELDS . '&$top=999';
        $members = [];
        while ($url !== '') {
            $body = $this->get($url);
            foreach ($this->rows($body['value'] ?? null) as $row) {
                if ($this->str($row['id'] ?? null) === '') {
                    continue;
                }
                $members[] = new EntraMember(
                    $this->str($row['id']),
                    $this->str($row['displayName'] ?? null),
                    $this->text($row['mail'] ?? null),
                    $this->text($row['userPrincipalName'] ?? null),
                    ($row['accountEnabled'] ?? true) !== false,
                );
            }
            $next = $body['@odata.nextLink'] ?? null;
            $url = is_string($next) ? $next : '';
        }

        return $members;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function get(string $url, array $headers = []): array
    {
        if (!$this->isConfigured()) {
            throw GraphNotConfigured::make();
        }

        try {
            $response = $this->http->getJson($url, ['Authorization' => 'Bearer ' . $this->accessToken()] + $headers);
        } catch (EntraGroupDirectoryUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('Microsoft Graph group read failed.');
        }

        $status = $response['status'];
        if ($status === 401 || $status === 403) {
            throw GraphPermissionNotGranted::make();
        }
        if ($status === 404) {
            throw EntraGroupNotFound::forId('(from ' . parse_url($url, PHP_URL_PATH) . ')');
        }
        if ($status < 200 || $status > 299) {
            throw new RuntimeException('Microsoft Graph group read returned HTTP ' . $status . '.');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Microsoft Graph group response was not valid JSON.');
        }

        return $this->assoc($decoded);
    }

    /** @return array<string, mixed> */
    private function assoc(mixed $value): array
    {
        $found = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key)) {
                $found[$key] = $item;
            }
        }

        return $found;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row)) {
                $rows[] = $this->assoc($row);
            }
        }

        return $rows;
    }

    private function str(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $row */
    private function group(array $row): ?EntraGroup
    {
        $id = $this->str($row['id'] ?? null);
        if ($id === '') {
            return null;
        }
        $name = $this->str($row['displayName'] ?? null);

        return new EntraGroup(
            $id,
            $name !== '' ? $name : $id,
            $this->text($row['mail'] ?? null),
            $this->text($row['description'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    private function accessToken(): string
    {
        $now = time();
        if ($this->token !== null && $now < $this->tokenExpiresAt - 60) {
            return $this->token;
        }

        try {
            $response = $this->http->postForm(
                sprintf(self::TOKEN_URL, rawurlencode($this->tenantId)),
                [],
                ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'scope' => self::SCOPE, 'grant_type' => 'client_credentials'],
            );
        } catch (Throwable) {
            throw new RuntimeException('Microsoft Graph token acquisition failed.');
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new RuntimeException('Microsoft Graph token acquisition returned HTTP ' . $response['status'] . '.');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Microsoft Graph token response was not valid JSON.');
        }
        $fields = $this->assoc($decoded);
        $token = $fields['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Microsoft Graph token response missing access_token.');
        }
        $expires = $fields['expires_in'] ?? null;
        $ttl = is_int($expires) || (is_string($expires) && is_numeric($expires)) ? (int) $expires : 3600;
        $this->token = $token;
        $this->tokenExpiresAt = $now + max(60, $ttl);

        return $token;
    }
}
