<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class ActiveOtpChallengeNotFound extends RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('No active email OTP challenge found for "%s".', $email));
    }

    public static function forChallenge(string $challengePublicId): self
    {
        return new self(sprintf('No matching email OTP challenge found for "%s".', $challengePublicId));
    }
}
