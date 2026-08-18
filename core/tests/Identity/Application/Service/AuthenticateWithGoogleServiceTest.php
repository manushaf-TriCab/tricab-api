<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\VerifiedGoogleIdToken;
use TriCab\Core\Identity\Application\Exception\GoogleIdTokenInvalid;
use TriCab\Core\Identity\Application\Exception\GoogleUserNotRegistered;
use TriCab\Core\Identity\Application\Service\AuthenticateWithGoogleService;
use TriCab\Core\Identity\Contract\GoogleIdTokenValidator;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\GoogleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserProvisioningRepository;

final class AuthenticateWithGoogleServiceTest extends TestCase
{
    public function test_authenticates_registered_active_user_and_links_identities(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $email = EmailAddress::fromString('admin@tricab.com');
        $users->register(
            emailAddress: $email,
            displayName: 'Admin',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('ADMINISTRATOR')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(
                EmailAddress::fromString('seed-admin@tricab.com'),
            ),
        );

        $service = new AuthenticateWithGoogleService(
            new FakeGoogleIdTokenValidator(new VerifiedGoogleIdToken(
                subjectId: 'google-sub-123',
                emailAddress: 'Admin@TriCab.com',
                emailVerified: true,
            )),
            $users,
        );

        $user = $service->execute('header.payload.signature');

        self::assertSame('admin@tricab.com', $user->emailAddress()->toString());
        self::assertNotNull($users->findByExternalIdentity(
            GoogleExternalIdentityFactory::forSubject('google-sub-123'),
        ));
        self::assertNotNull($users->findByExternalIdentity(
            EmailOtpExternalIdentityFactory::forEmail($email),
        ));
    }

    public function test_rejects_unregistered_email(): void
    {
        $service = new AuthenticateWithGoogleService(
            new FakeGoogleIdTokenValidator(new VerifiedGoogleIdToken(
                subjectId: 'google-sub-404',
                emailAddress: 'unknown@example.com',
                emailVerified: true,
            )),
            new InMemoryUserProvisioningRepository(),
        );

        $this->expectException(GoogleUserNotRegistered::class);
        $service->execute('header.payload.signature');
    }

    public function test_rejects_unverified_email(): void
    {
        $service = new AuthenticateWithGoogleService(
            new FakeGoogleIdTokenValidator(new VerifiedGoogleIdToken(
                subjectId: 'google-sub-uv',
                emailAddress: 'admin@tricab.com',
                emailVerified: false,
            )),
            new InMemoryUserProvisioningRepository(),
        );

        $this->expectException(GoogleIdTokenInvalid::class);
        $service->execute('header.payload.signature');
    }

    public function test_rejects_disabled_account(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $email = EmailAddress::fromString('disabled@tricab.com');
        $users->register(
            emailAddress: $email,
            displayName: 'Disabled',
            status: UserStatus::disabled(),
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(
                EmailAddress::fromString('seed-disabled@tricab.com'),
            ),
        );

        $service = new AuthenticateWithGoogleService(
            new FakeGoogleIdTokenValidator(new VerifiedGoogleIdToken(
                subjectId: 'google-sub-disabled',
                emailAddress: 'disabled@tricab.com',
                emailVerified: true,
            )),
            $users,
        );

        $this->expectException(AuthenticationNotAllowed::class);
        $service->execute('header.payload.signature');
    }
}

final class FakeGoogleIdTokenValidator implements GoogleIdTokenValidator
{
    public function __construct(
        private readonly VerifiedGoogleIdToken $verified,
    ) {
    }

    public function validate(string $idToken): VerifiedGoogleIdToken
    {
        unset($idToken);

        return $this->verified;
    }
}
