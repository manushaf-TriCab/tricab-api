<?php

declare(strict_types=1);

namespace TriCab\Api\ProductFinder;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderResult;
use TriCab\Core\ProductFinder\Contract\ProductFinderClient;

/**
 * The finder over localhost HTTP.
 *
 * The Python service listens on 127.0.0.1:8001 inside the same App Service and
 * has no nginx location, so this hop never leaves the instance. Plain HTTP is
 * correct here: `StreamJsonHttpClient` enforces no scheme, and the HTTPS
 * requirement in `Settings.php` is `isTrustedFoundryEndpoint`, which is about
 * calling out to Azure AI over the public internet. A loopback socket is not
 * that.
 *
 * **Nothing here throws.** An unreachable finder yields
 * `ProductFinderResult::unavailable()`, so the endpoint answers 200 with
 * `sizing: unavailable` — the same primary/fallback posture as
 * `FallbackAssistantResponseProvider`, and for the same reason: a customer's
 * enquiry should not die because a sidecar process is restarting.
 */
final class HttpProductFinderClient implements ProductFinderClient
{
    private ?bool $available = null;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $baseUrl,
    ) {
    }

    public function find(ProductFinderQuery $query): ProductFinderResult
    {
        try {
            $response = $this->http->postJson(
                $this->url('/api/find'),
                [],
                $query->toPayload(),
            );
        } catch (Throwable $exception) {
            return $this->down('Could not reach the product finder.', $exception);
        }

        $status = $response['status'];
        if ($status < 200 || $status >= 300) {
            // A 4xx from the finder is a validation failure it explained in the
            // body; surface that rather than a generic "unavailable", which
            // would send the customer looking for an outage.
            $decoded = $this->decode($response['body']);
            $error = $decoded['error'] ?? null;
            $message = is_array($error) ? ($error['message'] ?? null) : null;
            $detail = is_string($message) && $message !== ''
                ? $message
                : "The product finder returned HTTP {$status}.";

            return $this->down($detail, null);
        }

        $decoded = $this->decode($response['body']);
        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            return $this->down('The product finder returned an unreadable response.', null);
        }

        $this->available = true;

        /** @var array<string, mixed> $data */
        return ProductFinderResult::fromFinder($data);
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $response = $this->http->getJson($this->url('/api/health'));
            $this->available = $response['status'] >= 200 && $response['status'] < 300;
        } catch (Throwable $exception) {
            // error_log, not an injected PSR-3 logger: that is how the rest of
            // this layer reports (see EntraJwksClient, the guest provisioning
            // repository), and there is no LoggerInterface in the container to
            // inject.
            error_log('Product finder health check failed: ' . $exception->getMessage());
            $this->available = false;
        }

        return $this->available;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function down(string $detail, ?Throwable $exception): ProductFinderResult
    {
        $this->available = false;
        error_log(sprintf(
            'Product finder unavailable: %s%s',
            $detail,
            $exception !== null ? ' (' . $exception->getMessage() . ')' : '',
        ));

        return ProductFinderResult::unavailable($detail);
    }
}
