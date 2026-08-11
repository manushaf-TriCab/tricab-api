<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Observability;

/**
 * Per-request SQL/PDO timings. Never stores tokens, SQL parameters, or row payloads.
 */
final class RequestPerformanceMetrics
{
    public readonly string $requestId;

    private readonly float $startedHrtime;

    private float $pdoMs = 0.0;

    private float $tokenMs = 0.0;

    private int $sqlCount = 0;

    private float $sqlMs = 0.0;

    private float $slowestSqlMs = 0.0;

    private int $rowsReturned = 0;

    private string $dbAuthMode = '';

    private bool $tokenCacheHit = false;

    public function __construct(?string $requestId = null)
    {
        $this->requestId = $requestId ?? bin2hex(random_bytes(8));
        $this->startedHrtime = hrtime(true);
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
     *   db_auth_mode: string
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
        ];
    }
}
