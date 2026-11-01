<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use DateTimeImmutable;
use Throwable;
use TriCab\Api\Finance\Ap2\Ai\ErrorLogAp2AiUsageLog;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsageTotal;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageLog;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageReport;

/**
 * `finance.ap2_ai_usage` (`alter-finance-ap2-ai-usage.sql`): one row per AI call, no mail text. Every record also goes to
 * the log stream as before, and a database failure is logged and swallowed: logging must never fail the call it measures.
 */
final class AzureSqlAp2AiUsageLog extends AzureSqlAp2Support implements Ap2AiUsageLog, Ap2AiUsageReport
{
    public function __construct(SqlConnection $connection, private readonly ErrorLogAp2AiUsageLog $trace)
    {
        parent::__construct($connection);
    }

    public function record(Ap2AiUsage $usage): void
    {
        try {
            $this->trace->record($usage);
            $this->connection->execute(
                'INSERT INTO [finance].[ap2_ai_usage] ([purpose], [model], [prompt_version], [input_tokens], [output_tokens], '
                . '[cache_read_tokens], [cache_write_tokens], [cost_usd], [duration_ms], [calls], [ok]) '
                . 'VALUES (:purpose, :model, :prompt, :input, :output, :cache_read, :cache_write, :cost, :duration, :calls, :ok)',
                [
                    'purpose' => $usage->purpose,
                    'model' => $usage->model,
                    'prompt' => $usage->promptVersion,
                    'input' => $usage->inputTokens,
                    'output' => $usage->outputTokens,
                    'cache_read' => $usage->cacheReadTokens,
                    'cache_write' => $usage->cacheWriteTokens,
                    'cost' => number_format($usage->costUsd, 6, '.', ''),
                    'duration' => $usage->durationMs,
                    'calls' => $usage->calls,
                    'ok' => $usage->ok ? 1 : 0,
                ],
            );
        } catch (Throwable $failure) {
            error_log('Tri-AP AI usage was not saved: ' . $failure::class . ': ' . $failure->getMessage());
        }
    }

    public function totals(DateTimeImmutable $from, DateTimeImmutable $toExclusive): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [purpose], [model], SUM([calls]) AS [calls], SUM(CASE WHEN [ok] = 1 THEN 0 ELSE 1 END) AS [failed], '
            . 'SUM(CAST([input_tokens] AS BIGINT)) AS [input_tokens], SUM(CAST([output_tokens] AS BIGINT)) AS [output_tokens], '
            . 'SUM(CAST([cache_read_tokens] AS BIGINT)) AS [cache_read_tokens], SUM(CAST([cache_write_tokens] AS BIGINT)) AS [cache_write_tokens], '
            . 'SUM([cost_usd]) AS [cost_usd], SUM(CAST([duration_ms] AS BIGINT)) AS [duration_ms] '
            . 'FROM [finance].[ap2_ai_usage] WHERE [created_at] >= :from AND [created_at] < :to GROUP BY [purpose], [model] ORDER BY [purpose], [model]',
            ['from' => self::at($from), 'to' => self::at($toExclusive)],
        );

        return array_map(static fn (array $r): Ap2AiUsageTotal => new Ap2AiUsageTotal(
            self::text($r['purpose'] ?? ''),
            self::text($r['model'] ?? ''),
            self::int($r['calls'] ?? 0),
            self::int($r['failed'] ?? 0),
            self::int($r['input_tokens'] ?? 0),
            self::int($r['output_tokens'] ?? 0),
            self::int($r['cache_read_tokens'] ?? 0),
            self::int($r['cache_write_tokens'] ?? 0),
            round(self::amount($r['cost_usd'] ?? 0), 6),
            self::int($r['duration_ms'] ?? 0),
        ), $rows);
    }
}
