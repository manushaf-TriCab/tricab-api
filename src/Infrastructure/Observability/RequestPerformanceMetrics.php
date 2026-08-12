<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Observability;

/**
 * Per-request SQL/PDO timings. Never stores tokens, SQL parameters, or row payloads.
 */
final class RequestPerformanceMetrics
{
    private string $requestId;

    private float $startedHrtime;

    private float $pdoMs = 0.0;

    private float $tokenMs = 0.0;

    private int $sqlCount = 0;

    private float $sqlMs = 0.0;

    private float $slowestSqlMs = 0.0;

    private int $rowsReturned = 0;

    private string $dbAuthMode = '';

    private bool $tokenCacheHit = false;

    private string $cacheDriver = '';

    private ?bool $lookupsCacheHit = null;

    private ?bool $catalogueCacheHit = null;

    private int $dbConnectAttempts = 0;

    private int $dbConnectRetry = 0;

    private string $dbConnectRetryReason = '';

    private float $dbConnectFirstAttemptMs = 0.0;

    private float $dbConnectRetryMs = 0.0;

    public function __construct(?string $requestId = null)
    {
        $this->requestId = $requestId ?? bin2hex(random_bytes(8));
        $this->startedHrtime = hrtime(true);
    }

    public function reset(): void
    {
        $this->requestId = bin2hex(random_bytes(8));
        $this->startedHrtime = hrtime(true);
        $this->pdoMs = 0.0;
        $this->tokenMs = 0.0;
        $this->sqlCount = 0;
        $this->sqlMs = 0.0;
        $this->slowestSqlMs = 0.0;
        $this->rowsReturned = 0;
        $this->dbAuthMode = '';
        $this->tokenCacheHit = false;
        $this->lookupsCacheHit = null;
        $this->catalogueCacheHit = null;
        $this->dbConnectAttempts = 0;
        $this->dbConnectRetry = 0;
        $this->dbConnectRetryReason = '';
        $this->dbConnectFirstAttemptMs = 0.0;
        $this->dbConnectRetryMs = 0.0;
    }

    public static function durationMs(int|float $startedHrtime): float
    {
        return (hrtime(true) - $startedHrtime) / 1_000_000;
    }

    public function recordPdoCreate(float $milliseconds): void
    {
        $this->pdoMs += max(0.0, $milliseconds);
    }

    public function recordTokenAcquisition(float $milliseconds, bool $cacheHit): void
    {
        $this->tokenMs += max(0.0, $milliseconds);
        if ($cacheHit) {
            $this->tokenCacheHit = true;
        }
    }

    public function recordSql(float $milliseconds, int $rowCount = 0): void
    {
        $ms = max(0.0, $milliseconds);
        ++$this->sqlCount;
        $this->sqlMs += $ms;
        if ($ms > $this->slowestSqlMs) {
            $this->slowestSqlMs = $ms;
        }
        if ($rowCount > 0) {
            $this->rowsReturned += $rowCount;
        }
    }

    public function setDbAuthMode(string $mode): void
    {
        $this->dbAuthMode = $mode;
    }

    public function setCacheDriver(string $driver): void
    {
        $this->cacheDriver = $driver;
    }

    public function recordLookupsCacheHit(bool $hit): void
    {
        $this->lookupsCacheHit = $hit;
    }

    public function recordCatalogueCacheHit(bool $hit): void
    {
        $this->catalogueCacheHit = $hit;
    }

    public function recordConnectAttempt(int $attempt, float $milliseconds, ?string $transientReason): void
    {
        $ms = max(0.0, $milliseconds);
        $this->dbConnectAttempts = max($this->dbConnectAttempts, $attempt);
        if ($attempt === 1) {
            $this->dbConnectFirstAttemptMs = $ms;
        }
        if ($attempt > 1) {
            $this->dbConnectRetry = 1;
            $this->dbConnectRetryMs = $ms;
            if ($transientReason !== null && $transientReason !== '') {
                $this->dbConnectRetryReason = $transientReason;
            }
        } elseif ($transientReason !== null && $transientReason !== '' && $this->dbConnectRetryReason === '') {
            $this->dbConnectRetryReason = $transientReason;
        }
    }

    public function totalMs(): float
    {
        return self::durationMs($this->startedHrtime);
    }

    /**
     * @return array{
     *   request_id: string,
     *   pdo_ms: float,
     *   token_ms: float,
     *   token_cache_hit: bool,
     *   sql_count: int,
     *   sql_ms: float,
     *   slowest_sql_ms: float,
     *   rows: int,
     *   db_auth_mode: string,
     *   cache_driver: string,
     *   lookups_cache_hit: bool|null,
     *   catalogue_cache_hit: bool|null,
     *   db_connect_attempts: int,
     *   db_connect_retry: int,
     *   db_connect_retry_reason: string,
     *   db_connect_first_attempt_ms: float,
     *   db_connect_retry_ms: float
     * }
     */
    public function snapshot(): array
    {
        return [
            'request_id' => $this->requestId,
            'pdo_ms' => round($this->pdoMs, 2),
            'token_ms' => round($this->tokenMs, 2),
            'token_cache_hit' => $this->tokenCacheHit,
            'sql_count' => $this->sqlCount,
            'sql_ms' => round($this->sqlMs, 2),
            'slowest_sql_ms' => round($this->slowestSqlMs, 2),
            'rows' => $this->rowsReturned,
            'db_auth_mode' => $this->dbAuthMode,
            'cache_driver' => $this->cacheDriver,
            'lookups_cache_hit' => $this->lookupsCacheHit,
            'catalogue_cache_hit' => $this->catalogueCacheHit,
            'db_connect_attempts' => $this->dbConnectAttempts,
            'db_connect_retry' => $this->dbConnectRetry,
            'db_connect_retry_reason' => $this->dbConnectRetryReason,
            'db_connect_first_attempt_ms' => round($this->dbConnectFirstAttemptMs, 2),
            'db_connect_retry_ms' => round($this->dbConnectRetryMs, 2),
        ];
    }

    public function requestId(): string
    {
        return $this->requestId;
    }
}
