<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Http;

use JsonException;
use RuntimeException;

/**
 * Lightweight HTTPS client using PHP streams (no third-party HTTP SDK).
 */
final class StreamJsonHttpClient implements JsonHttpClient
{
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
    ) {
    }

    public function getJson(string $url, array $headers = []): array
    {
        return $this->request(
            'GET',
            $url,
            array_merge(['Accept' => 'application/json'], $headers),
            null,
        );
    }

    public function postJson(string $url, array $headers, array $payload): array
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $exception;
        }

        return $this->request(
            'POST',
            $url,
            array_merge(
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                $headers,
            ),
            $encoded,
        );
    }

    public function postForm(string $url, array $headers, array $fields): array
    {
        $encoded = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        return $this->request(
            'POST',
            $url,
            array_merge(
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                $headers,
            ),
            $encoded,
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $url, array $headers, ?string $body): array
    {
        $headerLines = [];
        if ($body !== null) {
            $headerLines[] = 'Content-Length: ' . (string) strlen($body);
        }
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $httpOptions = [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
        ];
        if ($body !== null) {
            $httpOptions['content'] = $body;
        }

        $context = stream_context_create([
            'http' => $httpOptions,
            'ssl' => $this->sslOptions(),
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('HTTP request failed.');
        }

        if (function_exists('http_get_last_response_headers')) {
            $http_response_header = http_get_last_response_headers();
        }
        $responseHeaders = $http_response_header;
        if (!is_array($responseHeaders)) {
            $responseHeaders = [];
        }

        return [
            'status' => self::statusFromResponseHeaders($responseHeaders),
            'body' => $responseBody,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sslOptions(): array
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ];
        $cafile = trim((string) ini_get('openssl.cafile'));
        if ($cafile === '' || !is_readable($cafile)) {
            foreach ([
                '/opt/homebrew/etc/openssl@3/cert.pem',
                '/opt/homebrew/etc/ca-certificates/cert.pem',
                '/etc/ssl/cert.pem',
            ] as $candidate) {
                if (is_readable($candidate)) {
                    $cafile = $candidate;
                    break;
                }
            }
        }
        if ($cafile !== '' && is_readable($cafile)) {
            $ssl['cafile'] = $cafile;
        }

        return $ssl;
    }

    /**
     * @param list<string> $headers
     */
    private static function statusFromResponseHeaders(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }

        return self::parseStatusCode($headers[0]);
    }

    private static function parseStatusCode(string $statusLine): int
    {
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
