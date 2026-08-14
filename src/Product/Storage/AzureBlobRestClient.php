<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Storage;

use RuntimeException;

/**
 * Low-level Azure Blob REST operations (HEAD / PUT / DELETE / COPY)
 * using the account key. Used by ProductBlobStore adapters.
 */
final class AzureBlobRestClient
{
    public function __construct(
        private readonly string $accountName,
        private readonly string $accountKey,
        private readonly string $blobEndpoint,
    ) {
    }

    /**
     * @return array{exists: bool, contentType: ?string, contentLength: ?int, etag: ?string}
     */
    public function head(string $container, string $blobPath): array
    {
        $response = $this->request('HEAD', $container, $blobPath);
        if ($response['status'] === 404) {
            return ['exists' => false, 'contentType' => null, 'contentLength' => null, 'etag' => null];
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Blob HEAD failed with HTTP ' . $response['status']);
        }

        return [
            'exists' => true,
            'contentType' => $response['headers']['content-type'] ?? null,
            'contentLength' => isset($response['headers']['content-length'])
                ? (int) $response['headers']['content-length']
                : null,
            'etag' => isset($response['headers']['etag'])
                ? trim((string) $response['headers']['etag'], '"')
                : null,
        ];
    }

    public function put(string $container, string $blobPath, string $contentType, string $binary): void
    {
        $response = $this->request('PUT', $container, $blobPath, $binary, [
            'Content-Type' => $contentType,
            'x-ms-blob-type' => 'BlockBlob',
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Blob PUT failed with HTTP ' . $response['status'] . ': ' . $response['body']);
        }
    }

    public function get(string $container, string $blobPath): ?string
    {
        $response = $this->request('GET', $container, $blobPath);
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Blob GET failed with HTTP ' . $response['status']);
        }

        return $response['body'];
    }

    public function delete(string $container, string $blobPath): void
    {
        $response = $this->request('DELETE', $container, $blobPath);
        if ($response['status'] === 404) {
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Blob DELETE failed with HTTP ' . $response['status']);
        }
    }

    public function copy(
        string $sourceContainer,
        string $sourcePath,
        string $destContainer,
        string $destPath,
    ): void {
        $sourceUrl = rtrim($this->blobEndpoint, '/')
            . '/' . rawurlencode($sourceContainer)
            . '/' . $this->encodeBlobPath($sourcePath);
        $response = $this->request('PUT', $destContainer, $destPath, null, [
            'x-ms-copy-source' => $sourceUrl,
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Blob COPY failed with HTTP ' . $response['status']);
        }
    }

    public function objectUrl(string $container, string $blobPath): string
    {
        return rtrim($this->blobEndpoint, '/')
            . '/' . rawurlencode($container)
            . '/' . $this->encodeBlobPath($blobPath);
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(
        string $method,
        string $container,
        string $blobPath,
        ?string $body = null,
        array $extraHeaders = [],
    ): array {
        $url = $this->objectUrl($container, $blobPath);
        $date = gmdate('D, d M Y H:i:s T');
        $contentLength = $body === null ? 0 : strlen($body);
        $contentType = $extraHeaders['Content-Type'] ?? '';

        $canonicalizedHeaders = $this->canonicalizeHeaders(array_merge($extraHeaders, [
            'x-ms-date' => $date,
            'x-ms-version' => '2022-11-02',
        ]));
        // Must match URL encoding exactly (e.g. spaces as %20). Unencoded spaces
        // in CanonicalizedResource cause SharedKey HTTP 403 while SAS PUT still works.
        $canonicalizedResource = '/' . $this->accountName
            . '/' . $container
            . '/' . $this->encodeBlobPath(ltrim($blobPath, '/'));

        $stringToSign = implode("\n", [
            strtoupper($method),
            '', // Content-Encoding
            '', // Content-Language
            $contentLength > 0 ? (string) $contentLength : '',
            '', // Content-MD5
            $contentType,
            '', // Date (using x-ms-date)
            '', // If-Modified-Since
            '', // If-Match
            '', // If-None-Match
            '', // If-Unmodified-Since
            '', // Range
            $canonicalizedHeaders,
            $canonicalizedResource,
        ]);

        $signature = base64_encode(hash_hmac(
            'sha256',
            $stringToSign,
            base64_decode($this->accountKey, true) ?: '',
            true,
        ));

        $headers = array_merge([
            'x-ms-date' => $date,
            'x-ms-version' => '2022-11-02',
            'Authorization' => 'SharedKey ' . $this->accountName . ':' . $signature,
        ], $extraHeaders);

        if ($body !== null) {
            $headers['Content-Length'] = (string) $contentLength;
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialise cURL for Blob request.');
        }

        $verb = strtoupper($method);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $verb,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 60,
            // HEAD must not wait for a body; Azure still sends Content-Length of the blob.
            CURLOPT_NOBODY => $verb === 'HEAD',
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // Azurite often uses http://127.0.0.1 — allow for non-production local endpoints only.
        if (str_starts_with($this->blobEndpoint, 'http://127.0.0.1') || str_starts_with($this->blobEndpoint, 'http://localhost')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Blob request failed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);
        $parsedHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $parsedHeaders[strtolower(trim($name))] = trim($value);
        }

        return ['status' => $status, 'headers' => $parsedHeaders, 'body' => $responseBody];
    }

    /**
     * @param array<string, string> $headers
     */
    private function canonicalizeHeaders(array $headers): string
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $key = strtolower(trim($name));
            if (!str_starts_with($key, 'x-ms-')) {
                continue;
            }
            $normalized[$key] = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        }
        ksort($normalized);
        $lines = [];
        foreach ($normalized as $name => $value) {
            $lines[] = $name . ':' . $value;
        }

        return implode("\n", $lines);
    }

    private function encodeBlobPath(string $blobPath): string
    {
        $parts = explode('/', ltrim($blobPath, '/'));

        return implode('/', array_map(static fn (string $part): string => rawurlencode($part), $parts));
    }
}
