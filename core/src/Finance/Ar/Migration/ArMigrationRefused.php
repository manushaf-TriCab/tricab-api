<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use RuntimeException;

/** `--apply` was asked for and something stands in the way; nothing was written. */
final class ArMigrationRefused extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Refused: ' . implode(' ', $reasons));
    }
}
