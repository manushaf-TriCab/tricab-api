<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Pricing;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Api\Support\JsonValue;
use TriCab\Core\Product\Application\Pricing\CostResolution;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;

/**
 * HTTP adapter for the interim legacy Cost API.
 * Never log defaultCost / calculatedCostPerMeter amounts.
 */
final class HttpLegacyCostCalculationGateway implements LegacyCostCalculationGateway
{
    /** @var array<string, CostResolution> */
    private array $requestCache = [];

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $baseUrl,
    ) {
    }

    public function resolveCost(string $productCode, string $dbco, string $requestedLength): CostResolution
    {
        $productCode = trim($productCode);
        $dbco = trim($dbco);
        $requestedLength = trim($requestedLength);

        if ($productCode === '' || $dbco === '' || $requestedLength === '' || !is_numeric($requestedLength) || bccomp($requestedLength, '0', 6) <= 0) {
            return CostResolution::failure($productCode, $dbco, 'INVALID_REQUEST', CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
        }

        $cacheKey = strtoupper($productCode) . '|' . $dbco . '|' . $requestedLength;
        if (isset($this->requestCache[$cacheKey])) {
            return $this->requestCache[$cacheKey];
        }

        $resolution = $this->requestWithRetry($productCode, $dbco, $requestedLength);
        $this->requestCache[$cacheKey] = $resolution;

        return $resolution;
    }

    private function requestWithRetry(string $productCode, string $dbco, string $requestedLength): CostResolution
    {
        $attempt = 0;
        $last = null;
        while ($attempt < 2) {
            ++$attempt;
            try {
                $result = $this->performRequest($productCode, $dbco, $requestedLength);
                if ($result['retryable'] && $attempt < 2) {
                    $last = $result['resolution'];
                    continue;
                }

                return $result['resolution'];
            } catch (Throwable $e) {
                $isTimeout = stripos($e->getMessage(), 'timeout') !== false
                    || stripos($e->getMessage(), 'timed out') !== false
                    || stripos($e->getMessage(), 'HTTP request failed') !== false;
                $last = CostResolution::failure(
                    $productCode,
                    $dbco,
                    $isTimeout ? 'TIMEOUT' : 'TRANSPORT_ERROR',
                    CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
                    $requestedLength,
                );
                if ($isTimeout && $attempt < 2) {
                    continue;
                }

                $this->metric('cost_api_timeout', $last->status);

                return $last;
            }
        }

        return $last ?? CostResolution::failure($productCode, $dbco, 'UNKNOWN', CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
    }

    /**
     * @return array{resolution: CostResolution, retryable: bool}
     */
    private function performRequest(string $productCode, string $dbco, string $requestedLength): array
    {
        $url = $this->buildUrl($productCode, $dbco, $requestedLength);
        $response = $this->http->getJson($url);
        $statusCode = JsonValue::int($response, 'status');
        $body = JsonValue::string($response, 'body');

        if ($statusCode >= 500 || $statusCode === 0) {
            $resolution = CostResolution::failure(
                $productCode,
                $dbco,
                $statusCode === 0 ? 'TRANSPORT_ERROR' : 'HTTP_' . $statusCode,
                CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
                $requestedLength,
            );
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => true];
        }

        if ($statusCode !== 200) {
            $resolution = CostResolution::failure(
                $productCode,
                $dbco,
                'HTTP_' . $statusCode,
                CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
                $requestedLength,
            );
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => false];
        }

        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $resolution = CostResolution::failure($productCode, $dbco, 'INVALID_JSON', CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => false];
        }

        if (!is_array($json)) {
            $resolution = CostResolution::failure($productCode, $dbco, 'INVALID_JSON', CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => false];
        }

        $successFlag = $json['success'] ?? false;
        if ($successFlag !== true && $successFlag !== 'true' && $successFlag !== 1) {
            $resolution = CostResolution::failure($productCode, $dbco, 'SUCCESS_FALSE', CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => false];
        }

        $data = $json['data'] ?? $json;
        if (!is_array($data)) {
            $resolution = CostResolution::failure($productCode, $dbco, 'MISSING_DATA', CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => false];
        }
        $data = JsonValue::toObject($data);

        $responseProduct = trim(JsonValue::string($data, 'productCode'));
        $responseDbco = trim(JsonValue::string($data, 'dbco', JsonValue::string($data, 'DBCO')));
        $status = strtoupper(trim(JsonValue::string($data, 'status')));

        if ($responseProduct === '' || strcasecmp($responseProduct, $productCode) !== 0) {
            $resolution = CostResolution::failure($productCode, $dbco, 'PRODUCT_CODE_MISMATCH', $status !== '' ? $status : CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => false];
        }

        if ($responseDbco === '' || (string) $responseDbco !== (string) $dbco) {
            $resolution = CostResolution::failure($productCode, $dbco, 'DBCO_MISMATCH', $status !== '' ? $status : CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => false];
        }

        // Live Cost API returns STOCK; customer pricing historically used STOCKED.
        if ($status === 'STOCK') {
            $status = CustomerSellPriceStatus::AVAILABILITY_STOCKED;
        }

        if (!in_array($status, [CustomerSellPriceStatus::AVAILABILITY_STOCKED, CustomerSellPriceStatus::AVAILABILITY_MAKE], true)) {
            $resolution = CostResolution::failure($productCode, $dbco, 'INVALID_STATUS', $status !== '' ? $status : CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
            $this->metric('cost_api_failure', $resolution->status);

            return ['resolution' => $resolution, 'retryable' => false];
        }

        $defaultCost = $this->nullableNumericString($data['defaultCost'] ?? null);
        $calculated = $this->nullableNumericString($data['calculatedCostPerMeter'] ?? null);
        $defaultLength = JsonValue::stringOrNull($data, 'defaultLength');
        $legacyUnit = JsonValue::stringOrNull($data, 'legacyUnitCode')
            ?? JsonValue::stringOrNull($data, 'unitCode');

        $resolution = new CostResolution(
            productCode: $productCode,
            dbco: $dbco,
            status: $status,
            defaultCost: $defaultCost,
            defaultLength: $defaultLength,
            requestedLength: $requestedLength,
            calculatedCostPerMeter: $calculated,
            legacyUnitCode: $legacyUnit,
            success: true,
            failureReason: null,
        );

        if ($resolution->effectiveCostPerUnit() === null) {
            $reason = $status === CustomerSellPriceStatus::AVAILABILITY_MAKE
                ? 'MISSING_CALCULATED_COST'
                : 'MISSING_DEFAULT_COST';
            $failed = CostResolution::failure($productCode, $dbco, $reason, $status, $requestedLength);
            $this->metric('cost_api_failure', $status);

            return ['resolution' => $failed, 'retryable' => false];
        }

        $this->metric('cost_api_success', $status);

        return ['resolution' => $resolution, 'retryable' => false];
    }

    private function buildUrl(string $productCode, string $dbco, string $requestedLength): string
    {
        $base = rtrim($this->baseUrl, '?&');
        if (!str_ends_with($base, '/')) {
            $base .= '/';
        }

        return $base . '?' . http_build_query([
            'DBCO' => $dbco,
            'userInputLength' => $requestedLength,
            'productCode' => $productCode,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function nullableNumericString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    private function metric(string $event, string $status): void
    {
        $normalized = strtoupper($status);
        if (!in_array($normalized, [CustomerSellPriceStatus::AVAILABILITY_MAKE, CustomerSellPriceStatus::AVAILABILITY_STOCKED], true)) {
            $normalized = CustomerSellPriceStatus::AVAILABILITY_UNKNOWN;
        }
        // One-liner metrics only — never log cost amounts.
        error_log($event . ' status=' . $normalized);
    }
}
