<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateInterval;
use DateTimeImmutable;
use TriCab\Core\Identity\Application\Exception\GuestLoginNotEligible;
use TriCab\Core\Identity\Contract\GuestPasskeyEmailSender;
use TriCab\Core\Identity\Contract\GuestPasskeySetupTokenRepository;
use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Entity\GuestPasskeySetupToken;
use TriCab\Core\Identity\Domain\Service\PasskeyTokenHasher;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Always returns a neutral accepted result. Never reveals whether the email exists.
 */
final class RequestGuestPasskeySetupLinkService
{
    public function __construct(
        private readonly GuestLoginEligibilityService $eligibility,
        private readonly UserRepository $users,
        private readonly GuestPasskeySetupTokenRepository $tokens,
        private readonly GuestPasskeyEmailSender $emailSender,
        private readonly PasskeyAuthRateLimiter $rateLimiter,
        private readonly string $publicAppBaseUrl,
        private readonly int $ttlSeconds = 900,
        private readonly Clock $clock = new SystemClock(),
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    public function execute(string $email, ?string $clientIp = null): void
    {
        $this->rateLimiter->assertSetupLinkAllowed($email, $clientIp);
        $emailAddress = EmailAddress::fromString($email);

        try {
            $this->eligibility->assertEligible($emailAddress);
            $user = $this->users->findByEmail($emailAddress);
            if ($user === null) {
                return;
            }
            $user->assertAuthenticationAllowed();
        } catch (GuestLoginNotEligible | AuthenticationNotAllowed) {
            return;
        }

        $raw = PasskeyTokenHasher::randomToken();
        $now = $this->clock->now();
        $token = new GuestPasskeySetupToken(
            id: null,
            publicId: $this->uuids->generate(),
            userId: $user->id(),
            tokenHash: PasskeyTokenHasher::hash($raw),
            expiresAtUtc: $now->add(new DateInterval('PT' . $this->ttlSeconds . 'S')),
            consumedAtUtc: null,
            createdAtUtc: $now,
            requestedIpHash: PasskeyTokenHasher::ipHash($clientIp),
        );
        $this->tokens->save($token);

        $base = rtrim($this->publicAppBaseUrl, '/');
        $url = $base . '/auth/guest/setup-passkey?t=' . rawurlencode($raw);
        try {
            $this->emailSender->sendPasskeySetupLink($emailAddress, $url, $token->expiresAtUtc());
        } catch (\Throwable) {
            // Neutral HTTP response must not reveal whether the mailbox exists.
        }
    }
}
