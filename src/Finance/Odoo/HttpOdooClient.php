<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Odoo;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Finance\Shared\Odoo\OdooClient;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * Odoo 19's JSON-2 API (`POST {url}/json/2/{model}/search_read`, bearer API key), as the
 * finance portal's `OdooClient` calls it: 500 rows a page ordered by id, the companies
 * named in the context, and `active_test = false` so an archived record arrives and can
 * be marked inactive instead of looking deleted. Read-only: the one method it has reads.
 *
 * The key is held here and put in one header; it is in no error, no log and no URL. An
 * error names the model and the HTTP status and at most 200 characters of Odoo's own
 * message (control characters removed), as the portal's does.
 */
final class HttpOdooClient implements OdooClient
{
    public const PAGE_SIZE = 500;

    /** A server that ignored the paging would otherwise be read forever. */
    public const MAX_PAGES = 2000;

    private readonly string $baseUrl;

    /**
     * @param list<int> $companyIds
     */
    public function __construct(
        private readonly JsonHttpClient $http,
        string $baseUrl,
        private readonly string $apiKey,
        private readonly array $companyIds,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function searchRead(string $model, array $domain, array $fields): array
    {
        $rows = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = $this->call($model, 'search_read', [
                'domain' => $domain,
                'fields' => $fields,
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
                'order' => 'id',
                'context' => ['allowed_company_ids' => $this->companyIds, 'active_test' => false],
            ]);
            foreach ($batch as $row) {
                $rows[] = $row;
            }
            if (count($batch) < self::PAGE_SIZE) {
                return $rows;
            }
        }

        throw new OdooError(sprintf('Odoo %s.search_read did not end after %d pages.', $model, self::MAX_PAGES));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function call(string $model, string $method, array $payload): array
    {
        $url = sprintf('%s/json/2/%s/%s', $this->baseUrl, rawurlencode($model), rawurlencode($method));
        try {
            $response = $this->http->postJson($url, ['Authorization' => 'bearer ' . $this->apiKey], $payload);
        } catch (Throwable $failure) {
            throw new OdooError(sprintf('Odoo %s.%s unreachable: %s', $model, $method, (new \ReflectionClass($failure))->getShortName()));
        }

        if ($response['status'] !== 200) {
            $message = self::message($response['body']);
            throw new OdooError(sprintf('Odoo %s.%s returned HTTP %d', $model, $method, $response['status']) . ($message !== '' ? ': ' . $message : ''));
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OdooError(sprintf('Odoo %s.%s answered with something that was not JSON.', $model, $method));
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new OdooError(sprintf('Odoo %s.%s answered with something that was not a list of records.', $model, $method));
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                throw new OdooError(sprintf('Odoo %s.%s answered with a record that was not an object.', $model, $method));
            }
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }

    private static function message(string $body): string
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }
        $text = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : '';

        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text), 0, 200);
    }
}
