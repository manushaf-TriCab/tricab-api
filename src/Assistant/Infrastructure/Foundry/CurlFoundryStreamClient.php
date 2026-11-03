<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Reads a Messages API event stream with curl, one event at a time.
 *
 * curl rather than PHP's stream wrapper because its write callback fires as
 * bytes arrive; the wrapper's chunked decoding can hold data back, which is the
 * thing streaming exists to avoid.
 */
final readonly class CurlFoundryStreamClient implements FoundryStreamClient
{
    private const MAX_ERROR_BODY = 2_000;

    public function __construct(
        private float $timeoutSeconds,
    ) {
    }

    public function stream(string $url, array $headers, array $payload, callable $onEvent): array
    {
        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Foundry stream request could not be encoded.', previous: $exception);
        }

        $headerLines = ['Content-Type: application/json', 'Accept: text/event-stream'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $status = 0;
        $buffer = '';
        $errorBody = '';
        $failure = null;

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('curl could not be initialised.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT_MS => 10_000,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                    $status = (int) $match[1];
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$status, &$buffer, &$errorBody, &$failure, $onEvent): int {
                if ($status < 200 || $status >= 300) {
                    if (strlen($errorBody) < self::MAX_ERROR_BODY) {
                        $errorBody .= substr($chunk, 0, self::MAX_ERROR_BODY - strlen($errorBody));
                    }

                    return strlen($chunk);
                }

                $buffer .= str_replace("\r\n", "\n", $chunk);
                try {
                    while (($end = strpos($buffer, "\n\n")) !== false) {
                        $frame = substr($buffer, 0, $end);
                        $buffer = substr($buffer, $end + 2);
                        self::dispatch($frame, $onEvent);
                    }
                } catch (Throwable $exception) {
                    // Thrown out of a curl callback, an exception would leave curl
                    // mid-transfer. Keep it, stop the transfer (returning less than
                    // was given aborts it), and rethrow once curl has returned.
                    $failure = $exception;

                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);

        if ($failure !== null) {
            throw $failure;
        }
        if ($ok === false && $errno === CURLE_OPERATION_TIMEDOUT) {
            throw new FoundryCallTimedOut($this->timeoutSeconds);
        }
        if ($ok === false) {
            throw new RuntimeException(sprintf('Foundry stream failed (curl %d: %s).', $errno, $error));
        }

        return ['status' => $status, 'body' => $errorBody];
    }

    /** @param callable(string, array<string, mixed>): void $onEvent */
    private static function dispatch(string $frame, callable $onEvent): void
    {
        $event = '';
        $data = [];
        foreach (explode("\n", $frame) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = ltrim($value, ' ');
            if ($field === 'event') {
                $event = $value;
            } elseif ($field === 'data') {
                $data[] = $value;
            }
        }
        if ($data === []) {
            return;
        }

        $decoded = json_decode(implode("\n", $data), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return;
        }
        /** @var array<string, mixed> $decoded */
        $type = is_string($decoded['type'] ?? null) ? $decoded['type'] : '';
        $onEvent($event !== '' ? $event : $type, $decoded);
    }
}
