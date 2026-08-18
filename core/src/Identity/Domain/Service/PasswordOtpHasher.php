<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Contract\OtpHasher;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

/**
 * OTP hasher using PHP password_hash / password_verify (PASSWORD_DEFAULT).
 */
final class PasswordOtpHasher implements OtpHasher
{
    public function hash(OtpCode $otpCode): string
    {
        return password_hash($otpCode->toString(), PASSWORD_DEFAULT);
    }

    public function verify(OtpCode $otpCode, string $hash): bool
    {
        return password_verify($otpCode->toString(), $hash);
    }
}
