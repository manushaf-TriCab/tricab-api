<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\GuestLoginEligibility;
use TriCab\Core\Identity\Application\Exception\GuestLoginNotEligible;
use TriCab\Core\Identity\Application\Service\CompositeGuestLoginEligibilityLookup;
use TriCab\Core\Identity\Application\Service\GuestLoginEligibilityService;
use TriCab\Core\Identity\Application\Service\RequestAndSendGuestOtpService;
use TriCab\Core\Identity\Application\Service\RequestGuestOtpService;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\PasswordOtpHasher;
use TriCab\Core\Identity\Domain\Service\SecureOtpGenerator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\FakeGuestLoginEligibilityLookup;
use TriCab\Core\Tests\Identity\Doubles\FakeGuestOtpEmailSender;
use TriCab\Core\Tests\Identity\Doubles\FakeOtpGenerator;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Identity\Doubles\InMemoryEmailOtpChallengeRepository;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserProvisioningRepository;

final class GuestLoginEligibilityServiceTest extends TestCase
{
    public function test_active_guest_without_customer_link_is_eligible(): void
    {
        $lookup = $this->lookupForUser(
            'guest@example.com',
            'GUEST',
            ['ACCOUNT.LOGIN'],
            UserStatus::active(),
        );
        $result = (new GuestLoginEligibilityService($lookup))
            ->assertEligible(EmailAddress::fromString('guest@example.com'));

        self::assertTrue($result->eligible);
        self::assertSame(GuestLoginEligibility::REASON_ELIGIBLE, $result->rejectionReasonCode());
    }

    public function test_active_employee_without_customer_link_is_eligible(): void
    {
        $lookup = $this->lookupForUser(
            'employee@tricab.com',
            'EMPLOYEE',
            ['ACCOUNT.LOGIN'],
            UserStatus::active(),
        );
        $result = (new GuestLoginEligibilityService($lookup))
            ->assertEligible(EmailAddress::fromString('employee@tricab.com'));

        self::assertTrue($result->eligible);
    }

    public function test_customer_user_is_eligible_regardless_of_customer_link(): void
    {
        $lookup = $this->lookupForUser(
            'customer@example.com',
            'CUSTOMER_USER',
            ['ACCOUNT.LOGIN'],
            UserStatus::active(),
        );
        $result = (new GuestLoginEligibilityService($lookup))
            ->assertEligible(EmailAddress::fromString('customer@example.com'));

        self::assertTrue($result->eligible);
    }

    public function test_unknown_email_rejected(): void
    {
        $lookup = $this->lookupForUser('known@example.com', 'GUEST', ['ACCOUNT.LOGIN'], UserStatus::active());

        try {
            (new GuestLoginEligibilityService($lookup))
                ->assertEligible(EmailAddress::fromString('unknown@example.com'));
            self::fail('Expected GuestLoginNotEligible');
        } catch (GuestLoginNotEligible $exception) {
            self::assertSame(GuestLoginEligibility::REASON_USER_NOT_FOUND, $exception->reasonCode);
        }
    }

    public function test_inactive_user_rejected(): void
    {
        $lookup = $this->lookupForUser(
            'inactive@example.com',
            'GUEST',
            ['ACCOUNT.LOGIN'],
            UserStatus::disabled(),
        );

        try {
            (new GuestLoginEligibilityService($lookup))
                ->assertEligible(EmailAddress::fromString('inactive@example.com'));
            self::fail('Expected GuestLoginNotEligible');
        } catch (GuestLoginNotEligible $exception) {
            self::assertSame(GuestLoginEligibility::REASON_USER_INACTIVE, $exception->reasonCode);
        }
    }

    public function test_user_without_account_login_rejected(): void
    {
        $lookup = $this->lookupForUser(
            'noperm@example.com',
            'CUSTOMER_USER',
            [],
            UserStatus::active(),
        );

        try {
            (new GuestLoginEligibilityService($lookup))
                ->assertEligible(EmailAddress::fromString('noperm@example.com'));
            self::fail('Expected GuestLoginNotEligible');
        } catch (GuestLoginNotEligible $exception) {
            self::assertSame(GuestLoginEligibility::REASON_MISSING_LOGIN_PERMISSION, $exception->reasonCode);
        }
    }

    public function test_missing_login_permission_simulates_expired_or_unassigned_role(): void
    {
        // In-memory users carry hydrated permissions; an empty permission set is the
        // application-level equivalent of an expired/unassigned ACCOUNT.LOGIN grant.
        $lookup = $this->lookupForUser(
            'expired@example.com',
            'GUEST',
            ['PRODUCT.READ'],
            UserStatus::active(),
        );

        try {
            (new GuestLoginEligibilityService($lookup))
                ->assertEligible(EmailAddress::fromString('expired@example.com'));
            self::fail('Expected GuestLoginNotEligible');
        } catch (GuestLoginNotEligible $exception) {
            self::assertSame(GuestLoginEligibility::REASON_MISSING_LOGIN_PERMISSION, $exception->reasonCode);
        }
    }

    public function test_rejected_email_does_not_generate_store_or_send_otp(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $sender = new FakeGuestOtpEmailSender();
        $eligibilityLookup = new FakeGuestLoginEligibilityLookup(GuestLoginEligibility::notFound());
        $otpGenerator = new FakeOtpGenerator(['123456']);

        $service = new RequestAndSendGuestOtpService(
            requestGuestOtp: new RequestGuestOtpService(
                challenges: $challenges,
                otpGenerator: $otpGenerator,
                otpHasher: new PasswordOtpHasher(),
                eligibility: new GuestLoginEligibilityService($eligibilityLookup),
                clock: $clock,
            ),
            emailSender: $sender,
            challenges: $challenges,
        );

        try {
            $service->execute('unknown@example.com');
            self::fail('Expected GuestLoginNotEligible');
        } catch (GuestLoginNotEligible) {
            // expected
        }

        self::assertSame([], $challenges->all());
        self::assertSame([], $sender->sent);
        self::assertSame(0, $otpGenerator->generateCount);
    }

    public function test_eligible_registered_user_sends_otp_once(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $sender = new FakeGuestOtpEmailSender();

        $service = new RequestAndSendGuestOtpService(
            requestGuestOtp: new RequestGuestOtpService(
                challenges: $challenges,
                otpGenerator: new SecureOtpGenerator(),
                otpHasher: new PasswordOtpHasher(),
                eligibility: new GuestLoginEligibilityService(new FakeGuestLoginEligibilityLookup()),
                clock: $clock,
            ),
            emailSender: $sender,
            challenges: $challenges,
        );

        $result = $service->execute('alex@example.com');

        self::assertNotSame('', $result->challengePublicId);
        self::assertCount(1, $sender->sent);
        self::assertCount(1, $challenges->all());
    }

    /**
     * @param list<string> $permissions
     */
    private function lookupForUser(
        string $email,
        string $role,
        array $permissions,
        UserStatus $status,
    ): CompositeGuestLoginEligibilityLookup {
        $users = new InMemoryUserProvisioningRepository();
        $emailAddress = EmailAddress::fromString($email);
        $users->register(
            emailAddress: $emailAddress,
            displayName: $email,
            status: $status,
            roles: [RoleCode::fromString($role)],
            permissions: array_map(
                static fn (string $code): PermissionCode => PermissionCode::fromString($code),
                $permissions,
            ),
            identity: EmailOtpExternalIdentityFactory::forEmail($emailAddress),
        );

        return new CompositeGuestLoginEligibilityLookup($users->inner());
    }
}
