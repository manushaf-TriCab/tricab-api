<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\Service\CreateGuestSessionService;
use TriCab\Core\Identity\Application\Service\ExchangeTrustedDeviceSessionService;
use TriCab\Core\Identity\Application\Service\GuestLoginEligibilityService;
use TriCab\Core\Identity\Application\Service\CompositeGuestLoginEligibilityLookup;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;
use TriCab\Core\Identity\Application\Service\RegisterTrustedDeviceService;
use TriCab\Core\Identity\Application\Service\RevokeTrustedDeviceService;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceInvalidCredential;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceIneligible;
use TriCab\Core\Identity\Domain\Service\Sha256SessionTokenHasher;
use TriCab\Core\Identity\Domain\Service\TrustedDeviceCredentialGenerator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\FakeGuestAccessTokenIssuer;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Identity\Doubles\InMemoryGuestSessionRepository;
use TriCab\Core\Tests\Identity\Doubles\InMemoryTrustedDeviceRepository;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserRepository;

final class TrustedDeviceServicesTest extends TestCase
{
    private const INSTALLATION = '11111111-1111-4111-8111-111111111111';

    public function test_register_stores_hash_only_and_returns_credential_once(): void
    {
        $clock = FixedClock::utc('2026-09-09T00:00:00+00:00');
        $devices = new InMemoryTrustedDeviceRepository($clock);
        $hasher = new Sha256SessionTokenHasher();
        $service = new RegisterTrustedDeviceService(
            devices: $devices,
            credentials: new TrustedDeviceCredentialGenerator($hasher),
            clock: $clock,
            ttlDays: 90,
        );

        $registered = $service->execute($this->guestUser(), self::INSTALLATION, 'ios', 'iPhone');

        self::assertNotSame('', $registered->credential);
        self::assertSame(1, $registered->credentialVersion);
        self::assertSame('2026-12-08T00:00:00+00:00', substr($registered->expiresAtUtc, 0, 19) . '+00:00');

        $stored = $devices->all()[0];
        self::assertSame(32, strlen($stored->credentialHash()->toBinary()));
        self::assertTrue($stored->credentialHash()->equals($hasher->hash($registered->credential)));
        self::assertStringNotContainsString($registered->credential, $stored->credentialHash()->toHex());
    }

    public function test_exchange_issues_session_and_rotates_credential(): void
    {
        [$register, $exchange, $devices, $hasher, $clock] = $this->services();
        $registered = $register->execute($this->guestUser(), self::INSTALLATION, 'ios');

        $exchanged = $exchange->execute(
            publicId: $registered->publicId,
            rawCredential: $registered->credential,
            installationId: self::INSTALLATION,
        );

        self::assertNotSame('', $exchanged->session->accessToken);
        self::assertNotSame($registered->credential, $exchanged->deviceCredential->credential);
        self::assertSame(2, $exchanged->deviceCredential->credentialVersion);

        // Rotate again so the original credential falls out of the previous-grace window.
        $again = $exchange->execute(
            publicId: $registered->publicId,
            rawCredential: $exchanged->deviceCredential->credential,
            installationId: self::INSTALLATION,
        );
        self::assertSame(3, $again->deviceCredential->credentialVersion);

        $this->expectException(TrustedDeviceInvalidCredential::class);
        $exchange->execute(
            publicId: $registered->publicId,
            rawCredential: $registered->credential,
            installationId: self::INSTALLATION,
        );
    }

    public function test_previous_credential_works_during_grace_then_issues_replacement(): void
    {
        [$register, $exchange] = $this->services();
        $registered = $register->execute($this->guestUser(), self::INSTALLATION, 'android');
        $first = $exchange->execute($registered->publicId, $registered->credential, self::INSTALLATION);

        // Simulate lost rotation response: client still has pre-rotation credential.
        $recovery = $exchange->execute($registered->publicId, $registered->credential, self::INSTALLATION);
        self::assertNotSame('', $recovery->session->accessToken);
        self::assertNotSame($first->deviceCredential->credential, $recovery->deviceCredential->credential);
    }

    public function test_wrong_installation_is_rejected(): void
    {
        [$register, $exchange] = $this->services();
        $registered = $register->execute($this->guestUser(), self::INSTALLATION, 'ios');

        $this->expectException(TrustedDeviceInvalidCredential::class);
        $exchange->execute(
            $registered->publicId,
            $registered->credential,
            '22222222-2222-4222-8222-222222222222',
        );
    }

    public function test_revoked_device_cannot_exchange(): void
    {
        [$register, $exchange, $devices, , $clock] = $this->services();
        $registered = $register->execute($this->guestUser(), self::INSTALLATION, 'ios');
        (new RevokeTrustedDeviceService($devices, $clock))->revokeCurrent(
            UserId::fromInt(9),
            $registered->publicId,
        );

        $this->expectException(\TriCab\Core\Identity\Domain\Exception\TrustedDeviceRevoked::class);
        $exchange->execute($registered->publicId, $registered->credential, self::INSTALLATION);
    }

    public function test_expired_device_cannot_exchange(): void
    {
        $clock = FixedClock::utc('2026-09-09T00:00:00+00:00');
        $devices = new InMemoryTrustedDeviceRepository($clock);
        $users = new InMemoryUserRepository();
        $users->save($this->guestUser());
        $hasher = new Sha256SessionTokenHasher();
        $register = new RegisterTrustedDeviceService(
            devices: $devices,
            credentials: new TrustedDeviceCredentialGenerator($hasher),
            clock: $clock,
            ttlDays: 1,
        );
        $registered = $register->execute($this->guestUser(), self::INSTALLATION, 'ios');

        $later = FixedClock::utc('2026-09-11T00:00:00+00:00');
        $exchange = $this->exchangeService($devices, $users, $hasher, $later);

        $this->expectException(\TriCab\Core\Identity\Domain\Exception\TrustedDeviceExpired::class);
        $exchange->execute($registered->publicId, $registered->credential, self::INSTALLATION);
    }

    public function test_disabled_user_cannot_exchange(): void
    {
        [$register, $exchange, , , , $users] = $this->services();
        $registered = $register->execute($this->guestUser(), self::INSTALLATION, 'ios');
        $users->save($this->guestUser(UserStatus::disabled()));

        $this->expectException(TrustedDeviceIneligible::class);
        $exchange->execute($registered->publicId, $registered->credential, self::INSTALLATION);
    }

    public function test_missing_account_login_blocks_register(): void
    {
        $clock = FixedClock::utc('2026-09-09T00:00:00+00:00');
        $service = new RegisterTrustedDeviceService(
            devices: new InMemoryTrustedDeviceRepository($clock),
            clock: $clock,
        );

        $this->expectException(TrustedDeviceIneligible::class);
        $service->execute($this->guestUserWithoutLogin(), self::INSTALLATION, 'ios');
    }

    /**
     * @return array{
     *   0: RegisterTrustedDeviceService,
     *   1: ExchangeTrustedDeviceSessionService,
     *   2: InMemoryTrustedDeviceRepository,
     *   3: Sha256SessionTokenHasher,
     *   4: FixedClock,
     *   5: InMemoryUserRepository
     * }
     */
    private function services(): array
    {
        $clock = FixedClock::utc('2026-09-09T00:00:00+00:00');
        $devices = new InMemoryTrustedDeviceRepository($clock);
        $users = new InMemoryUserRepository();
        $users->save($this->guestUser());
        $hasher = new Sha256SessionTokenHasher();

        return [
            new RegisterTrustedDeviceService(
                devices: $devices,
                credentials: new TrustedDeviceCredentialGenerator($hasher),
                clock: $clock,
                ttlDays: 90,
            ),
            $this->exchangeService($devices, $users, $hasher, $clock),
            $devices,
            $hasher,
            $clock,
            $users,
        ];
    }

    private function exchangeService(
        InMemoryTrustedDeviceRepository $devices,
        InMemoryUserRepository $users,
        Sha256SessionTokenHasher $hasher,
        FixedClock $clock,
    ): ExchangeTrustedDeviceSessionService {
        $sessions = new InMemoryGuestSessionRepository($clock);

        return new ExchangeTrustedDeviceSessionService(
            devices: $devices,
            users: $users,
            issueGuestSession: new IssueGuestSessionService(
                createGuestSession: new CreateGuestSessionService($sessions, clock: $clock, ttl: 'PT24H'),
                tokenIssuer: new FakeGuestAccessTokenIssuer(),
                tokenHasher: $hasher,
                clock: $clock,
                ttlSeconds: 86400,
            ),
            eligibility: new GuestLoginEligibilityService(new CompositeGuestLoginEligibilityLookup($users)),
            credentials: new TrustedDeviceCredentialGenerator($hasher),
            clock: $clock,
        );
    }

    private function guestUser(?UserStatus $status = null): User
    {
        return new User(
            UserId::fromInt(9),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440099'),
            EmailAddress::fromString('guest@example.com'),
            'guest@example.com',
            $status ?? UserStatus::active(),
            [RoleCode::fromString('GUEST')],
            [PermissionCode::fromString('ACCOUNT.LOGIN')],
        );
    }

    private function guestUserWithoutLogin(): User
    {
        return new User(
            UserId::fromInt(10),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440010'),
            EmailAddress::fromString('nologin@example.com'),
            'nologin@example.com',
            UserStatus::active(),
            [RoleCode::fromString('GUEST')],
            [],
        );
    }
}
