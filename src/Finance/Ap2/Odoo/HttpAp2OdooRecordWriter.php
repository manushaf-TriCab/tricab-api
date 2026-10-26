<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Odoo;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooRecordWriter;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * Odoo 19's JSON-2 `create` (`POST {url}/json/2/{model}/create`, `{"vals_list": [values]}`, bearer API key), as
 * {@see \TriCab\Api\Finance\Odoo\HttpOdooClient} calls `search_read`. Nothing leaves this class while `$enabled`
 * (FINANCE_AP2_ODOO_POSTING_ENABLED) is false: the refusal comes before any request.
 *
 * Only these models may be created: a vendor bill and the external id that names it. The key is in one header and in
 * no error or log.
 */
final class HttpAp2OdooRecordWriter implements Ap2OdooRecordWriter
{
    public const MODELS = ['account.move', 'ir.model.data'];

    private readonly string $baseUrl;

    /** @param list<int> $companyIds */
    public function __construct(
        private readonly JsonHttpClient $http,
        string $baseUrl,
        private readonly string $apiKey,
        private readonly array $companyIds,
        private readonly bool $enabled,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function create(string $model, array $values): int
    {
        if (!$this->enabled) {
            throw new Ap2OdooPostingDisabled();
        }
        if (!in_array($model, self::MODELS, true)) {
            throw new OdooError(sprintf('Tri-AP2 does not create %s records in Odoo.', $model));
        }
        if ($this->baseUrl === '' || trim($this->apiKey) === '') {
            throw new OdooError('Odoo is not configured (ODOO_URL, ODOO_API_KEY).');
        }

        $url = sprintf('%s/json/2/%s/create', $this->baseUrl, rawurlencode($model));
        try {
            $response = $this->http->postJson($url, ['Authorization' => 'bearer ' . $this->apiKey], [
                'vals_list' => [$values],
                'context' => ['allowed_company_ids' => $this->companyIds],
            ]);
        } catch (Throwable $failure) {
            throw new OdooError(sprintf('Odoo %s.create unreachable: %s', $model, (new \ReflectionClass($failure))->getShortName()));
        }
        if ($response['status'] !== 200) {
            $message = self::message($response['body']);
            throw new OdooError(sprintf('Odoo %s.create returned HTTP %d', $model, $response['status']) . ($message !== '' ? ': ' . $message : ''));
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OdooError(sprintf('Odoo %s.create answered with something that was not JSON.', $model));
        }
        // `create(vals_list)` answers the new ids; a single id is accepted too.
        $id = is_array($decoded) && array_is_list($decoded) && count($decoded) === 1 ? $decoded[0] : $decoded;
        if (!is_int($id) || $id <= 0) {
            throw new OdooError(sprintf('Odoo %s.create did not answer the new record id.', $model));
        }

        return $id;
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
