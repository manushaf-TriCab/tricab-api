<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Service\IdentityResolutionService;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\GoogleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddressPurpose;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserProvisioningRepository;

/**
 * Phase P — one person, several verified addresses.
 *
 * The case these tests exist for: HR invites a candidate on a personal
 * address, they complete their induction by email OTP, they start and are
 * issued a TriCab mailbox. Before this change they could use exactly one of
 * the two, because resolution compared the provider's verified email against
 * the single [identity].[Users].[EmailAddress].
 */
final class MultiAddressIdentityResolutionTest extends TestCase
{
    private const PERSONAL = 'jane.candidate@example.com';
    private const CORPORATE = 'jane.smith@tricab.com';

    public function test_one_person_resolves_from_either_address(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $otpIdentity = EmailOtpExternalIdentityFactory::forEmail(
            EmailAddress::fromString(self::PERSONAL),
        );

        // Invited as a candidate: one user, primary address personal.
        $candidate = $users->register(
            emailAddress: EmailAddress::fromString(self::PERSONAL),
            displayName: 'Jane',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: $otpIdentity,
        );

        // Day one: the TriCab mailbox is recorded against the same person, and
        // their Entra identity is linked to it.
        $users->addVerifiedAddress(
            $candidate->id(),
            EmailAddress::fromString(self::CORPORATE),
            EmailAddressPurpose::corporate(),
        );
        $users->linkExternalIdentity($candidate, $this->entraIdentity('oid-jane'));

        $service = new IdentityResolutionService($users);

        $viaOtp = $service->resolve($otpIdentity, EmailAddress::fromString(self::PERSONAL));
        $viaEntra = $service->resolve(
            $this->entraIdentity('oid-jane'),
            EmailAddress::fromString(self::CORPORATE),
        );

        self::assertTrue($viaOtp->id()->equals($viaEntra->id()));
        self::assertTrue($viaOtp->id()->equals($candidate->id()));
    }

    /**
     * The regression that motivated the whole phase. Linking Entra used to
     * force the primary address to the corporate one, and that broke the OTP
     * login the person had been using for weeks.
     */
    public function test_linking_a_second_address_does_not_break_the_first_login(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $otpIdentity = EmailOtpExternalIdentityFactory::forEmail(
            EmailAddress::fromString(self::PERSONAL),
        );

        $candidate = $users->register(
            emailAddress: EmailAddress::fromString(self::PERSONAL),
            displayName: 'Jane',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: $otpIdentity,
        );
        $users->addVerifiedAddress(
            $candidate->id(),
            EmailAddress::fromString(self::CORPORATE),
            EmailAddressPurpose::corporate(),
        );

        $service = new IdentityResolutionService($users);
        $stillWorks = $service->resolve($otpIdentity, EmailAddress::fromString(self::PERSONAL));

        self::assertTrue($stillWorks->id()->equals($candidate->id()));
    }

    /**
     * A corporate address admits Entra and nothing else. Without this, anyone
     * who registered a consumer Google account bearing someone's TriCab
     * address would auto-link to their platform user and inherit their roles.
     */
    public function test_a_corporate_address_does_not_admit_a_social_provider(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $employee = $users->register(
            emailAddress: EmailAddress::fromString(self::CORPORATE),
            displayName: 'Jane',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: $this->entraIdentity('oid-jane'),
        );
        $users->addVerifiedAddress(
            $employee->id(),
            EmailAddress::fromString(self::CORPORATE),
            EmailAddressPurpose::corporate(),
        );

        $service = new IdentityResolutionService($users);

        $this->expectException(UserNotRegistered::class);
        $service->resolve(
            GoogleExternalIdentityFactory::forSubject('google-impostor'),
            EmailAddress::fromString(self::CORPORATE),
        );
    }

    /**
     * And the other direction: an Entra identity must not auto-link to a
     * self-asserted personal address, or a tenant guest invitation could
     * resolve to an existing person.
     */
    public function test_a_personal_address_does_not_admit_entra(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $candidate = $users->register(
            emailAddress: EmailAddress::fromString(self::PERSONAL),
            displayName: 'Jane',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString(self::PERSONAL)),
        );
        $users->addVerifiedAddress(
            $candidate->id(),
            EmailAddress::fromString(self::PERSONAL),
            EmailAddressPurpose::personal(),
        );

        $service = new IdentityResolutionService($users);

        $this->expectException(UserNotRegistered::class);
        $service->resolve($this->entraIdentity('oid-stranger'), EmailAddress::fromString(self::PERSONAL));
    }

    /**
     * A refused binding is indistinguishable from an unknown address. A
     * distinct error would confirm to a stranger that the address belongs to
     * somebody here — the same enumeration the guest OTP flow already avoids.
     */
    public function test_a_refused_binding_looks_like_an_unknown_address(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $employee = $users->register(
            emailAddress: EmailAddress::fromString(self::CORPORATE),
            displayName: 'Jane',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: $this->entraIdentity('oid-jane'),
        );
        $users->addVerifiedAddress(
            $employee->id(),
            EmailAddress::fromString(self::CORPORATE),
            EmailAddressPurpose::corporate(),
        );

        $service = new IdentityResolutionService($users);
        $google = GoogleExternalIdentityFactory::forSubject('google-x');

        $refused = null;
        try {
            $service->resolve($google, EmailAddress::fromString(self::CORPORATE));
        } catch (UserNotRegistered $e) {
            $refused = $e->getMessage();
        }

        $unknown = null;
        try {
            $service->resolve($google, EmailAddress::fromString('nobody@example.com'));
        } catch (UserNotRegistered $e) {
            $unknown = $e->getMessage();
        }

        self::assertNotNull($refused);
        self::assertSame($unknown, $refused);
    }

    /**
     * An address nobody has classified resolves exactly as it did before this
     * table existed. Applying the migration must not change any behaviour by
     * itself — that is what makes it safe to deploy ahead of the backfill.
     */
    public function test_an_unclassified_address_is_unaffected_by_the_binding(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $users->register(
            emailAddress: EmailAddress::fromString('legacy@somewhere.test'),
            displayName: 'Legacy',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(
                EmailAddress::fromString('legacy@somewhere.test'),
            ),
        );

        // First Microsoft sign-in, on an address that is on no TriCab domain —
        // a contractor, or an onmicrosoft.com account. A purpose guessed from
        // the domain would call this personal and refuse it.
        $service = new IdentityResolutionService($users);
        $user = $service->resolve(
            $this->entraIdentity('oid-legacy-new'),
            EmailAddress::fromString('legacy@somewhere.test'),
        );

        self::assertSame('legacy@somewhere.test', $user->emailAddress()->toString());
    }

    private function entraIdentity(string $subject): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::microsoftEntra(),
            ExternalSubjectId::fromString($subject),
            ExternalTenantId::fromString('tenant-1'),
        );
    }
}
