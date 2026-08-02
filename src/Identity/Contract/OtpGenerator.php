<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

interface OtpGenerator
{
    public function generate(): OtpCode;
}
