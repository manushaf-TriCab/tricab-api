<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use RuntimeException;

/** A source value the target cannot hold. `$kind` is a fixed code word (never the value) that goes in the report. */
final class ArMigrationBadValue extends RuntimeException
{
    public function __construct(public readonly string $kind)
    {
        parent::__construct($kind);
    }
}
