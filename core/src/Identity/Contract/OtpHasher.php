<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

interface OtpHasher
{
    public function hash(OtpCode $otpCode): string;

    public function verify(OtpCode $otpCode, string $hash): bool;
}
