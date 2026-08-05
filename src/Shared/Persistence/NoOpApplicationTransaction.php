<?php

declare(strict_types=1);

namespace TriCab\Api\Shared\Persistence;

use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * No-op transaction boundary for USER_REPOSITORY=memory mode, where there is
 * no shared connection/resource to coordinate across repositories.
 */
final class NoOpApplicationTransaction implements ApplicationTransaction
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}
