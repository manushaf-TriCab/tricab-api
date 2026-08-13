<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Observability;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;

/**
 * Logs request-level PDO/SQL metrics. Never logs tokens, Authorization, or SQL parameters.
 */
final class RequestPerformanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly RequestPerformanceMetrics $metrics,
        private readonly ApplicationCache $cache,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->metrics->reset();
        $this->metrics->setCacheDriver($this->cache->driver());
        $requestId = trim($request->getHeaderLine('X-Request-Id'));
        if ($requestId === '') {
            $requestId = $this->metrics->requestId();
        }

        $request = $request->withAttribute('requestPerformanceId', $requestId);

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $exception) {
            $this->log($request, 500);
            throw $exception;
        }

        $this->log($request, $response->getStatusCode());

        return $response;
    }

    private function log(ServerRequestInterface $request, int $status): void
    {
        if (!$this->settings->performanceLogging) {
            return;
        }

        $snap = $this->metrics->snapshot();
        $line = sprintf(
            '%s %s status=%d total_ms=%.0f pdo_ms=%.0f pdo_connect_ms=%.0f first_query_ms=%.0f token_ms=%.0f token_cache_hit=%s token_expires_in_seconds=%d sql_count=%d sql_ms=%.0f slowest_sql_ms=%.0f rows=%d db_auth=%s cache_driver=%s lookups_cache_hit=%s catalogue_cache_hit=%s db_connect_attempts=%d db_connect_retry=%d db_connect_retry_reason=%s db_connect_first_attempt_ms=%.0f db_connect_retry_ms=%.0f request_id=%s',
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $status,
            $this->metrics->totalMs(),
            $snap['pdo_ms'],
            $snap['pdo_connect_ms'],
            $snap['first_query_ms'],
            $snap['token_ms'],
            $snap['token_cache_hit'] ? '1' : '0',
            $snap['token_expires_in_seconds'],
            $snap['sql_count'],
            $snap['sql_ms'],
            $snap['slowest_sql_ms'],
            $snap['rows'],
            $snap['db_auth_mode'] !== '' ? $snap['db_auth_mode'] : '-',
            $snap['cache_driver'] !== '' ? $snap['cache_driver'] : $this->cache->driver(),
            self::nullableFlag($snap['lookups_cache_hit']),
            self::nullableFlag($snap['catalogue_cache_hit']),
            $snap['db_connect_attempts'],
            $snap['db_connect_retry'],
            $snap['db_connect_retry_reason'] !== '' ? $snap['db_connect_retry_reason'] : '-',
            $snap['db_connect_first_attempt_ms'],
            $snap['db_connect_retry_ms'],
            $this->metrics->requestId(),
        );
        $named = $snap['named_queries'] ?? [];
        if (is_array($named) && $named !== []) {
            $parts = [];
            foreach ($named as $query) {
                if (!is_array($query)) {
                    continue;
                }
                $parts[] = sprintf(
                    '%s:%dr/%.0fms',
                    (string) ($query['name'] ?? ''),
                    (int) ($query['rows'] ?? 0),
                    (float) ($query['ms'] ?? 0),
                );
            }
            if ($parts !== []) {
                $line .= ' named=' . implode(',', $parts);
            }
        }

        error_log('[perf] ' . $line);

        $logFile = $this->resolveLogFile();
        if ($logFile !== null) {
            @file_put_contents(
                $logFile,
                sprintf("[%s] %s\n", gmdate('c'), $line),
                FILE_APPEND | LOCK_EX,
            );
        }
    }

    private static function nullableFlag(?bool $value): string
    {
        if ($value === null) {
            return '-';
        }

        return $value ? '1' : '0';
    }

    private function resolveLogFile(): ?string
    {
        $dir = dirname(__DIR__, 3) . '/var/log';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/request-performance.log';
    }
}
