<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Ai;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiPricing;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;

/** The cost of a call, and the usage that is logged for it. */
final class Ap2AiPricingTest extends TestCase
{
    public function testHaikuPricing(): void
    {
        $price = Ap2AiPricing::haiku55();
        // 10,000 in at 0.10/M + 1,000 out at 0.50/M.
        self::assertSame(0.0015, $price->cost(10_000, 1_000));
        // Over 100K prompt tokens (cache reads count) the long-context price applies; cache reads at a tenth.
        self::assertSame(0.05275, $price->cost(100_000, 1_000, 5_000));
        self::assertSame(0.0, Ap2AiPricing::unknown()->cost(10_000, 1_000));
    }

    public function testUsageAddsUpOverCalls(): void
    {
        $one = new Ap2AiUsage(Ap2AiUsage::PURPOSE_COPILOT, 'claude-haiku-5-5', 'p-v1', 1000, 100, 0, 0, 0.00015, 900, 1, true);
        $sum = Ap2AiUsage::none(Ap2AiUsage::PURPOSE_COPILOT, 'claude-haiku-5-5', 'p-v1')->plus($one)->plus($one);

        self::assertSame([2000, 200, 0.0003, 1800, 2, true], [$sum->inputTokens, $sum->outputTokens, $sum->costUsd, $sum->durationMs, $sum->calls, $sum->ok]);
        self::assertFalse($sum->failed()->ok);
    }
}
