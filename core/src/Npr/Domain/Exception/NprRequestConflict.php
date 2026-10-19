<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain\Exception;

use RuntimeException;

/** A save based on a version that is no longer current. Nothing was written; maps to 409. */
final class NprRequestConflict extends RuntimeException
{
    public function __construct(public readonly string $currentVersion)
    {
        parent::__construct('This request was changed by another action. Reload it before saving.');
    }
}
