<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/**
 * USD per million tokens for one deployment, so each call's cost can be logged. Foundry bills Claude at the
 * Anthropic list price. Haiku 5.5: 0.10 in / 0.50 out for prompts up to 100K tokens (0.50 / 2.50 beyond); cache
 * reads are billed at a tenth of the input price, cache writes at 1.25 times.
 */
final readonly class Ap2AiPricing
{
    private const LONG_PROMPT_TOKENS = 100_000;

    public function __construct(
        public float $inputPerMillion,
        public float $outputPerMillion,
        public float $longInputPerMillion = 0.0,
        public float $longOutputPerMillion = 0.0,
    ) {
    }

    public static function haiku55(): self
    {
        return new self(0.10, 0.50, 0.50, 2.50);
    }

    /** By deployment name (Foundry deployments are named after the model): the 5.5 models; anything else unknown. */
    public static function forModel(string $deployment): self
    {
        $name = strtolower($deployment);

        return match (true) {
            str_contains($name, 'haiku-5-5') => self::haiku55(),
            str_contains($name, 'sonnet-5-5') => new self(2.0, 10.0),
            str_contains($name, 'opus-5-5') => new self(4.0, 20.0),
            default => self::unknown(),
        };
    }

    /** No price known: tokens are still logged, the cost is 0. */
    public static function unknown(): self
    {
        return new self(0.0, 0.0);
    }

    public function cost(int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $prompt = $input + $cacheRead + $cacheWrite;
        $long = $prompt > self::LONG_PROMPT_TOKENS && $this->longInputPerMillion > 0.0;
        $in = $long ? $this->longInputPerMillion : $this->inputPerMillion;
        $out = $long && $this->longOutputPerMillion > 0.0 ? $this->longOutputPerMillion : $this->outputPerMillion;

        return round(($input * $in + $cacheRead * $in * 0.1 + $cacheWrite * $in * 1.25 + $output * $out) / 1_000_000, 6);
    }
}
