<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;

final class WindowedPasskeyAuthRateLimiter implements PasskeyAuthRateLimiter
{
    public function __construct(
        private readonly ApplicationCache $cache,
        private readonly int $emailLimit = 3,
        private readonly int $ipLimit = 10,
        private readonly int $windowSeconds = 900,
    ) {
    }

    public function assertSetupLinkAllowed(?string $email, ?string $clientIp): void
    {
        $this->hit('passkey-setup-email:' . strtolower(trim((string) $email)), $this->emailLimit);
        $this->hit('passkey-setup-ip:' . (string) $clientIp, $this->ipLimit);
    }

    public function assertVerifyAllowed(?string $clientIp): void
    {
        $this->hit('passkey-verify-ip:' . (string) $clientIp, 20);
    }

    public function assertCeremonyAllowed(?string $clientIp): void
    {
        $this->hit('passkey-ceremony-ip:' . (string) $clientIp, 20);
    }

    private function hit(string $key, int $limit): void
    {
        $count = $this->cache->get($key);
        $n = is_int($count) ? $count : 0;
        if ($n >= $limit) {
            throw PasskeyAuthFailed::rateLimited();
        }
        $this->cache->set($key, $n + 1, $this->windowSeconds);
    }
}
