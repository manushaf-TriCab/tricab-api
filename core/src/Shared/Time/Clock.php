<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Time;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
