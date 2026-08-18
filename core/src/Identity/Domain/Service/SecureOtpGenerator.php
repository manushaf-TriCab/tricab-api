<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use Random\RandomException;
use TriCab\Core\Identity\Contract\OtpGenerator;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

/**
 * Cryptographically secure six-digit numeric OTP generator.
 */
final class SecureOtpGenerator implements OtpGenerator
{
    public function generate(): OtpCode
    {
        try {
            $number = random_int(0, 999999);
        } catch (RandomException $exception) {
            throw new RandomException('Unable to generate a secure OTP.', 0, $exception);
        }

        return OtpCode::fromString(sprintf('%06d', $number));
    }
}
