<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use RuntimeException;

/**
 * One Foundry call ran past its own time limit (FOUNDRY_TIMEOUT_SECONDS).
 * Distinct from other transport failures so the trace can say "timeout".
 */
final class FoundryCallTimedOut extends RuntimeException
{
    public function __construct(public readonly float $limitSeconds, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Foundry call exceeded its %s second limit.', rtrim(rtrim(number_format($limitSeconds, 1, '.', ''), '0'), '.')), 0, $previous);
    }
}
