<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/** What one purpose and model used over a period: the sum of its logged {@see Ap2AiUsage} rows. */
final readonly class Ap2AiUsageTotal
{
    public function __construct(
        public string $purpose,
        public string $model,
        public int $calls,
        public int $failed,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public float $costUsd,
        public int $durationMs,
    ) {
    }
}
