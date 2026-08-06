<?php

declare(strict_types=1);

namespace TriCab\Core\Customer;

/**
 * Marker for the Customer bounded-context namespace.
 * Value objects, entities, contracts, and use cases follow Identity's
 * User Management structure (see src/Identity).
 */
final class Module
{
    public const NAME = 'Customer';
}
