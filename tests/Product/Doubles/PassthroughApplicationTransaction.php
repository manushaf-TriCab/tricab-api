<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class PassthroughApplicationTransaction implements ApplicationTransaction
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}
