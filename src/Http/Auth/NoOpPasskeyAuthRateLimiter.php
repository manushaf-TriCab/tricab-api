<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;

final class NoOpPasskeyAuthRateLimiter implements PasskeyAuthRateLimiter
{
    public function assertSetupLinkAllowed(?string $email, ?string $clientIp): void
    {
        unset($email, $clientIp);
    }

    public function assertVerifyAllowed(?string $clientIp): void
    {
        unset($clientIp);
    }

    public function assertCeremonyAllowed(?string $clientIp): void
    {
        unset($clientIp);
    }
}
