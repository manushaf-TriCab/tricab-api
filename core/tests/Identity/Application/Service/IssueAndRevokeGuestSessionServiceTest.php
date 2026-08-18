<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\Exception\GuestSessionIssuanceFailed;
use TriCab\Core\Identity\Application\Exception\GuestSessionNotFound;
use TriCab\Core\Identity\Application\Service\CreateGuestSessionService;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;
use TriCab\Core\Identity\Application\Service\RevokeGuestSessionService;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\Sha256SessionTokenHasher;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\FailingGuestSessionRepository;
use TriCab\Core\Tests\Identity\Doubles\FakeGuestAccessTokenIssuer;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Identity\Doubles\InMemoryGuestSessionRepository;

final class IssueAndRevokeGuestSessionServiceTest extends TestCase
{
    public function test_issue_persists_hash_only_and_returns_24h_token(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $sessions = new InMemoryGuestSessionRepository($clock);
        $hasher = new Sha256SessionTokenHasher();

        $service = new IssueGuestSessionService(
            createGuestSession: new CreateGuestSessionService($sessions, clock: $clock, ttl: 'PT24H'),
            tokenIssuer: new FakeGuestAccessTokenIssuer(),
            tokenHasher: $hasher,
            clock: $clock,
            ttlSeconds: 86400,
        );

        $user = $this->guestUser();
        $issued = $service->execute($user);

        self::assertSame('Bearer', $issued->tokenType);
        self::assertSame(86400, $issued->expiresIn);
        self::assertSame('2026-08-01T00:00:00+00:00', substr($issued->expiresAtUtc, 0, 19) . '+00:00');
        self::assertContains('GUEST', $issued->user['roles']);
        self::assertContains('ACCOUNT.LOGIN', $issued->user['permissions']);
        self::assertNotSame('', $issued->accessToken);

        self::assertCount(1, $sessions->all());
        $session = $sessions->all()[0];
        self::assertSame(32, strlen($session->sessionTokenHash()->toBinary()));
        self::assertTrue($session->sessionTokenHash()->equals($hasher->hash($issued->accessToken)));
        self::assertStringNotContainsString($issued->accessToken, $session->sessionTokenHash()->toHex());
    }

    public function test_persistence_failure_does_not_return_token(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');

        $service = new IssueGuestSessionService(
            createGuestSession: new CreateGuestSessionService(new FailingGuestSessionRepository(), clock: $clock),
            tokenIssuer: new FakeGuestAccessTokenIssuer(),
            tokenHasher: new Sha256SessionTokenHasher(),
            clock: $clock,
        );

        try {
            $service->execute($this->guestUser());
            self::fail('Expected GuestSessionIssuanceFailed');
        } catch (GuestSessionIssuanceFailed $exception) {
            self::assertSame('Guest session persistence failed.', $exception->getMessage());
            self::assertStringNotContainsString('eyJ', $exception->getMessage());
        }
    }

    public function test_revoke_is_idempotent(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $sessions = new InMemoryGuestSessionRepository($clock);
        $hasher = new Sha256SessionTokenHasher();

        $issued = (new IssueGuestSessionService(
            createGuestSession: new CreateGuestSessionService($sessions, clock: $clock),
            tokenIssuer: new FakeGuestAccessTokenIssuer(),
            tokenHasher: $hasher,
            clock: $clock,
        ))->execute($this->guestUser());

        $revoke = new RevokeGuestSessionService($sessions, $clock);
        $hash = $hasher->hash($issued->accessToken);
        $revoke->revokeByTokenHash($hash);
        $revoke->revokeByTokenHash($hash);

        self::assertTrue($sessions->all()[0]->isRevoked());
        self::assertFalse($sessions->all()[0]->isActive($clock->now()));
    }

    public function test_revoke_missing_session_fails(): void
    {
        $this->expectException(GuestSessionNotFound::class);

        (new RevokeGuestSessionService(new InMemoryGuestSessionRepository()))
            ->revokeByTokenHash((new Sha256SessionTokenHasher())->hash('missing-token'));
    }

    private function guestUser(): User
    {
        return new User(
            UserId::fromInt(9),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440099'),
            EmailAddress::fromString('guest@example.com'),
            'guest@example.com',
            UserStatus::active(),
            [RoleCode::fromString('GUEST')],
            [PermissionCode::fromString('ACCOUNT.LOGIN')],
        );
    }
}
