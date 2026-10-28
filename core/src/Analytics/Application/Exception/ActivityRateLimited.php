<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Application\Exception;

use RuntimeException;

final class ActivityRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many activity requests.');
    }
}
