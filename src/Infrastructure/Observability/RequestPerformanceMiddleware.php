<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Observability;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\Config\Settings;

/**
 * Logs request-level PDO/SQL metrics. Never logs tokens, Authorization, or SQL parameters.
 */
final class RequestPerformanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly RequestPerformanceMetrics $metrics,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = trim($request->getHeaderLine('X-Request-Id'));
        if ($requestId === '') {
            $requestId = $this->metrics->requestId;
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
            '%s %s status=%d total_ms=%.0f pdo_ms=%.0f token_ms=%.0f token_cache_hit=%s sql_count=%d sql_ms=%.0f slowest_sql_ms=%.0f rows=%d db_auth=%s request_id=%s',
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $status,
            $this->metrics->totalMs(),
            $snap['pdo_ms'],
            $snap['token_ms'],
            $snap['token_cache_hit'] ? '1' : '0',
            $snap['sql_count'],
            $snap['sql_ms'],
            $snap['slowest_sql_ms'],
            $snap['rows'],
            $snap['db_auth_mode'] !== '' ? $snap['db_auth_mode'] : '-',
            $this->metrics->requestId,
        );

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

    private function resolveLogFile(): ?string
    {
        $dir = dirname(__DIR__, 3) . '/var/log';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/request-performance.log';
    }
}
