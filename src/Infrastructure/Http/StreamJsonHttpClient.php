<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Http;

use RuntimeException;

/**
 * Lightweight HTTPS JSON client using PHP streams (no third-party HTTP SDK).
 */
final class StreamJsonHttpClient implements JsonHttpClient
{
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
    ) {
    }

    public function postJson(string $url, array $headers, array $payload): array
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $headerLines = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . (string) strlen($encoded),
        ];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

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

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $encoded,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => $ssl,
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed.');
        }

        return [
            'status' => self::statusFromResponseHeaders($http_response_header),
            'body' => $body,
        ];
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
