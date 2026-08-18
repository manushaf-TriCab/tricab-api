<?php

declare(strict_types=1);

namespace TriCab\Core\Shared;

/**
 * Marker for the Shared bounded-context namespace.
 * Domain primitives shared across modules will be added in later stages.
 */
final class Package
{
    public const NAME = 'tricab/tricab-core';
}
