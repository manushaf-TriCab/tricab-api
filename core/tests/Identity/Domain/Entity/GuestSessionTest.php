<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\Entity;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Entity\GuestSession;
use TriCab\Core\Identity\Domain\Exception\GuestSessionExpired;
use TriCab\Core\Identity\Domain\Exception\GuestSessionRevoked;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

final class GuestSessionTest extends TestCase
{
    public function test_active_until_revoked_or_expired(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $session = $this->session($clock);

        self::assertTrue($session->isActive($clock->now()));
        self::assertFalse($session->isRevoked());

        $session->revoke($clock->now());
        self::assertTrue($session->isRevoked());
        self::assertFalse($session->isActive($clock->now()));
    }

    public function test_touch_updates_last_used_when_active(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $session = $this->session($clock);

        $clock->advance('+1 hour');
        $session->touch($clock->now());

        self::assertNotNull($session->lastUsedAtUtc());
        self::assertTrue($session->lastUsedAtUtc() == $clock->now());
    }

    public function test_expired_session_cannot_be_touched(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $session = $this->session($clock);
        $clock->advance('+13 hours');

        $this->expectException(GuestSessionExpired::class);
        $session->touch($clock->now());
    }

    public function test_revoked_session_assert_active_fails(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $session = $this->session($clock);
        $session->revoke($clock->now());

        $this->expectException(GuestSessionRevoked::class);
        $session->assertActive($clock->now());
    }

    private function session(FixedClock $clock): GuestSession
    {
        $now = $clock->now();

        return GuestSession::open(
            publicId: (new UuidV4Generator())->generate(),
            userId: UserId::fromInt(1),
            sessionTokenHash: SessionTokenHash::fromHex(str_repeat('ab', 32)),
            issuedAtUtc: $now,
            expiresAtUtc: $now->add(new DateInterval('PT12H')),
        );
    }
}
