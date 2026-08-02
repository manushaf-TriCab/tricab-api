<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\Exception\ActiveOtpChallengeNotFound;
use TriCab\Core\Identity\Application\Service\CreateGuestSessionService;
use TriCab\Core\Identity\Application\Service\GuestUserProvisioningService;
use TriCab\Core\Identity\Application\Service\RequestGuestOtpService;
use TriCab\Core\Identity\Application\Service\VerifyGuestOtpService;
use TriCab\Core\Identity\Domain\Exception\OtpInvalid;
use TriCab\Core\Identity\Domain\Service\PasswordOtpHasher;
use TriCab\Core\Identity\Domain\Service\SecureOtpGenerator;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Identity\Doubles\InMemoryEmailOtpChallengeRepository;
use TriCab\Core\Tests\Identity\Doubles\InMemoryGuestSessionRepository;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserProvisioningRepository;

final class GuestOtpApplicationServicesTest extends TestCase
{
    public function test_request_invalidates_previous_active_challenges_and_returns_plaintext_otp(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $service = new RequestGuestOtpService(
            challenges: $challenges,
            otpGenerator: new SecureOtpGenerator(),
            otpHasher: new PasswordOtpHasher(),
            clock: $clock,
        );

        $first = $service->execute(' Guest@Example.COM ');
        $second = $service->execute('guest@example.com');

        self::assertSame('guest@example.com', $first->emailAddress);
        self::assertMatchesRegularExpression('/^\d{6}$/', $first->plaintextOtp);
        self::assertCount(2, $challenges->all());
        self::assertTrue($challenges->all()[0]->isInvalidated());
        self::assertTrue($challenges->all()[1]->isActive($clock->now()));
        self::assertNotSame($first->challengePublicId, $second->challengePublicId);
    }

    public function test_verify_provisions_guest_user_with_email_otp_identity(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $users = new InMemoryUserProvisioningRepository();
        $hasher = new PasswordOtpHasher();

        $requested = (new RequestGuestOtpService($challenges, new SecureOtpGenerator(), $hasher, clock: $clock))
            ->execute('guest@example.com');

        $authenticated = (new VerifyGuestOtpService(
            $challenges,
            $hasher,
            new GuestUserProvisioningService($users),
            $clock,
        ))->execute('guest@example.com', $requested->plaintextOtp);

        self::assertSame('guest@example.com', $authenticated->emailAddress()->toString());
        self::assertSame('active', $authenticated->status()->toString());
        self::assertContains('GUEST', array_map(
            static fn ($role) => $role->toString(),
            $authenticated->roles(),
        ));
        self::assertContains('ACCOUNT.LOGIN', array_map(
            static fn ($permission) => $permission->toString(),
            $authenticated->permissions(),
        ));

        $linked = $users->findByExternalIdentity(
            \TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory::forEmail(
                \TriCab\Core\Identity\Domain\ValueObject\EmailAddress::fromString('guest@example.com'),
            ),
        );
        self::assertNotNull($linked);
        self::assertTrue(ExternalProvider::emailOtp()->equals(
            \TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory::forEmail(
                \TriCab\Core\Identity\Domain\ValueObject\EmailAddress::fromString('guest@example.com'),
            )->provider(),
        ));
    }

    public function test_verify_rejects_invalid_otp_and_increments_attempts(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $hasher = new PasswordOtpHasher();

        (new RequestGuestOtpService($challenges, new SecureOtpGenerator(), $hasher, clock: $clock))
            ->execute('guest@example.com');

        $verify = new VerifyGuestOtpService(
            $challenges,
            $hasher,
            new GuestUserProvisioningService(new InMemoryUserProvisioningRepository()),
            $clock,
        );

        try {
            $verify->execute('guest@example.com', '000000');
            self::fail('Expected OtpInvalid');
        } catch (OtpInvalid) {
            self::assertSame(1, $challenges->all()[0]->attemptCount());
        }
    }

    public function test_verify_fails_when_no_active_challenge_exists(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');

        $this->expectException(ActiveOtpChallengeNotFound::class);

        (new VerifyGuestOtpService(
            new InMemoryEmailOtpChallengeRepository($clock),
            new PasswordOtpHasher(),
            new GuestUserProvisioningService(new InMemoryUserProvisioningRepository()),
            $clock,
        ))->execute('nobody@example.com', '123456');
    }

    public function test_create_guest_session_persists_session_for_authenticated_user(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $users = new InMemoryUserProvisioningRepository();
        $sessions = new InMemoryGuestSessionRepository($clock);
        $hasher = new PasswordOtpHasher();

        $requested = (new RequestGuestOtpService($challenges, new SecureOtpGenerator(), $hasher, clock: $clock))
            ->execute('guest@example.com');
        $user = (new VerifyGuestOtpService(
            $challenges,
            $hasher,
            new GuestUserProvisioningService($users),
            $clock,
        ))->execute('guest@example.com', $requested->plaintextOtp);

        self::assertSame('guest@example.com', $user->emailAddress()->toString());

        $session = (new CreateGuestSessionService($sessions, clock: $clock))->execute(
            user: $user,
            sessionTokenHash: SessionTokenHash::fromHex(str_repeat('cd', 32)),
        );

        self::assertNotNull($session->id());
        self::assertTrue($session->userId()->equals($user->id()));
        self::assertTrue($session->isActive($clock->now()));
        self::assertCount(1, $sessions->all());
    }

    public function test_verify_requires_matching_challenge_public_id(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $hasher = new PasswordOtpHasher();

        $requested = (new RequestGuestOtpService($challenges, new SecureOtpGenerator(), $hasher, clock: $clock))
            ->execute('guest@example.com');

        $verify = new VerifyGuestOtpService(
            $challenges,
            $hasher,
            new GuestUserProvisioningService(new InMemoryUserProvisioningRepository()),
            $clock,
        );

        $this->expectException(ActiveOtpChallengeNotFound::class);
        $verify->execute('other@example.com', $requested->plaintextOtp, $requested->challengePublicId);
    }

    public function test_verify_succeeds_with_challenge_public_id(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $hasher = new PasswordOtpHasher();

        $requested = (new RequestGuestOtpService($challenges, new SecureOtpGenerator(), $hasher, clock: $clock))
            ->execute('guest@example.com');

        $user = (new VerifyGuestOtpService(
            $challenges,
            $hasher,
            new GuestUserProvisioningService(new InMemoryUserProvisioningRepository()),
            $clock,
        ))->execute('guest@example.com', $requested->plaintextOtp, $requested->challengePublicId);

        self::assertSame('guest@example.com', $user->emailAddress()->toString());
    }

    public function test_email_normalization_rejects_empty_and_oversized_values(): void
    {
        $this->expectException(\TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue::class);
        \TriCab\Core\Identity\Domain\ValueObject\EmailAddress::fromString('   ');
    }
}
