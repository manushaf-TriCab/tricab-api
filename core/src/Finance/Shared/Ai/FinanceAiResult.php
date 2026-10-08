<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Ai;

/**
 * A purpose's answer. `result` is the object the purpose's schema describes;
 * `model` is what produced it, so a stored result can say so.
 */
final readonly class FinanceAiResult
{
    /**
     * @param array<array-key, mixed> $result
     */
    public function __construct(
        public string $purpose,
        public array $result,
        public string $correlationId,
        public string $provider,
        public string $model,
    ) {
    }
}
