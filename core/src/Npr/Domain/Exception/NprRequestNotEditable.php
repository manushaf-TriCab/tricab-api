<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain\Exception;

use RuntimeException;

/** The request is past the point where Sales may change it. Maps to 409. */
final class NprRequestNotEditable extends RuntimeException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(sprintf("A request that is '%s' cannot be edited.", $status));
    }
}
