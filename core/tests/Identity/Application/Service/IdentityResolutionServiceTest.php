<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Service\IdentityResolutionService;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Service\AppleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\GoogleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserProvisioningRepository;

final class IdentityResolutionServiceTest extends TestCase
{
    public function test_existing_user_with_prelinked_microsoft_identity(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $email = EmailAddress::fromString('staff@tricab.com');
        $identity = $this->microsoftIdentity('oid-linked');
        $users->register(
            emailAddress: $email,
            displayName: 'Staff',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: $identity,
        );

        $service = new IdentityResolutionService($users);
        $user = $service->resolve($identity, $email);

        self::assertSame('staff@tricab.com', $user->emailAddress()->toString());
    }

    public function test_microsoft_identity_links_by_verified_email_when_not_prelinked(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $email = EmailAddress::fromString('staff@tricab.com');
        $users->register(
            emailAddress: $email,
            displayName: 'Staff',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed@tricab.com')),
        );

        $service = new IdentityResolutionService($users);
        $identity = $this->microsoftIdentity('oid-new');
        $user = $service->resolve($identity, $email);

        self::assertSame('staff@tricab.com', $user->emailAddress()->toString());
        self::assertNotNull($users->findByExternalIdentity($identity));
        self::assertSame('microsoft_entra', $identity->provider()->toString());
    }

    public function test_google_verified_email_resolves_same_roles(): void
    {
        $users = $this->repositoryWithUser('guest@example.com', UserStatus::active(), ['GUEST'], ['ACCOUNT.LOGIN', 'PRODUCT.READ']);
        $identity = GoogleExternalIdentityFactory::forSubject('google-sub-1');

        $service = new IdentityResolutionService($users);
        $user = $service->resolve($identity, EmailAddress::fromString('guest@example.com'));

        self::assertSame(['GUEST'], array_map(static fn (RoleCode $r) => $r->toString(), $user->roles()));
        self::assertSame(['ACCOUNT.LOGIN', 'PRODUCT.READ'], array_map(static fn (PermissionCode $p) => $p->toString(), $user->permissions()));
    }

    public function test_apple_verified_email_links_identity(): void
    {
        $users = $this->repositoryWithUser('guest@example.com', UserStatus::active(), ['GUEST'], ['ACCOUNT.LOGIN']);
        $identity = AppleExternalIdentityFactory::forSubject('apple-sub-1');

        $service = new IdentityResolutionService($users);
        $service->resolve($identity, EmailAddress::fromString('guest@example.com'));

        self::assertNotNull($users->findByExternalIdentity($identity));
    }

    public function test_email_otp_resolves_existing_user(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $email = EmailAddress::fromString('guest@example.com');
        $users->register(
            emailAddress: $email,
            displayName: 'Guest',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: $this->microsoftIdentity('oid-seed'),
        );
        $identity = EmailOtpExternalIdentityFactory::forEmail($email);

        $service = new IdentityResolutionService($users);
        $user = $service->resolve($identity, $email);

        self::assertSame('guest@example.com', $user->emailAddress()->toString());
        self::assertNotNull($users->findByExternalIdentity($identity));
    }

    public function test_unknown_verified_email_is_not_registered(): void
    {
        $service = new IdentityResolutionService(new InMemoryUserProvisioningRepository());

        $this->expectException(UserNotRegistered::class);
        $service->resolve(
            GoogleExternalIdentityFactory::forSubject('google-unknown'),
            EmailAddress::fromString('unknown@example.com'),
        );
    }

    public function test_multiple_users_with_same_email_is_ambiguous(): void
    {
        $users = new class extends InMemoryUserProvisioningRepository {
            public function findAllByEmail(EmailAddress $emailAddress): array
            {
                $user = $this->findByEmail($emailAddress);

                return $user !== null ? [$user, $user] : [];
            }
        };
        $email = EmailAddress::fromString('duplicate@tricab.com');
        $users->register(
            emailAddress: $email,
            displayName: 'Duplicate',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed-dup@tricab.com')),
        );

        $service = new IdentityResolutionService($users);

        $this->expectException(IdentityAmbiguous::class);
        $service->resolve(
            GoogleExternalIdentityFactory::forSubject('google-dup'),
            $email,
        );
    }

    public function test_provider_subject_linked_to_another_user_is_conflict(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $users->register(
            emailAddress: EmailAddress::fromString('owner@tricab.com'),
            displayName: 'Owner',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: GoogleExternalIdentityFactory::forSubject('google-sub-taken'),
        );
        $users->register(
            emailAddress: EmailAddress::fromString('other@tricab.com'),
            displayName: 'Other',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed-other@tricab.com')),
        );

        $service = new IdentityResolutionService($users);

        $this->expectException(IdentityConflict::class);
        $service->resolve(
            GoogleExternalIdentityFactory::forSubject('google-sub-taken'),
            EmailAddress::fromString('other@tricab.com'),
        );
    }

    public function test_user_with_different_provider_subject_is_conflict(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $email = EmailAddress::fromString('staff@tricab.com');
        $users->register(
            emailAddress: $email,
            displayName: 'Staff',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('EMPLOYEE')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: $this->microsoftIdentity('oid-existing'),
        );

        $service = new IdentityResolutionService($users);

        $this->expectException(IdentityConflict::class);
        $service->resolve($this->microsoftIdentity('oid-different'), $email);
    }

    public function test_suspended_user_cannot_authenticate(): void
    {
        $users = $this->repositoryWithUser('disabled@tricab.com', UserStatus::disabled(), ['EMPLOYEE'], ['ACCOUNT.LOGIN']);
        $identity = $this->microsoftIdentity('oid-disabled');

        $service = new IdentityResolutionService($users);

        $this->expectException(AuthenticationNotAllowed::class);
        $service->resolve($identity, EmailAddress::fromString('disabled@tricab.com'));
    }

    public function test_resolution_does_not_change_roles(): void
    {
        $users = $this->repositoryWithUser('staff@tricab.com', UserStatus::active(), ['ADMINISTRATOR'], ['ACCOUNT.LOGIN', 'USER.READ']);
        $before = $users->findByEmail(EmailAddress::fromString('staff@tricab.com'));
        self::assertNotNull($before);

        $service = new IdentityResolutionService($users);
        $after = $service->resolve(
            GoogleExternalIdentityFactory::forSubject('google-admin'),
            EmailAddress::fromString('staff@tricab.com'),
        );

        self::assertSame(
            array_map(static fn (RoleCode $r) => $r->toString(), $before->roles()),
            array_map(static fn (RoleCode $r) => $r->toString(), $after->roles()),
        );
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function repositoryWithUser(string $email, UserStatus $status, array $roles, array $permissions): InMemoryUserProvisioningRepository
    {
        $users = new InMemoryUserProvisioningRepository();
        $users->register(
            emailAddress: EmailAddress::fromString($email),
            displayName: 'User',
            status: $status,
            roles: array_map(static fn (string $code) => RoleCode::fromString($code), $roles),
            permissions: array_map(static fn (string $code) => PermissionCode::fromString($code), $permissions),
            identity: EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed-' . $email)),
        );

        return $users;
    }

    private function microsoftIdentity(string $subject): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::microsoftEntra(),
            ExternalSubjectId::fromString($subject),
            ExternalTenantId::fromString('tenant-1'),
        );
    }
}
