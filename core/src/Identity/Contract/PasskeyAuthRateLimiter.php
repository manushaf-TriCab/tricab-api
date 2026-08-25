<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

interface PasskeyAuthRateLimiter
{
    public function assertSetupLinkAllowed(?string $email, ?string $clientIp): void;

    public function assertVerifyAllowed(?string $clientIp): void;

    public function assertCeremonyAllowed(?string $clientIp): void;
}
