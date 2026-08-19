<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\VerifiedAppleIdToken;
use TriCab\Core\Identity\Application\Exception\AppleIdTokenInvalid;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Service\AuthenticateWithAppleService;
use TriCab\Core\Identity\Application\Service\IdentityResolutionService;
use TriCab\Core\Identity\Contract\AppleAuthorizationCodeExchanger;
use TriCab\Core\Identity\Contract\AppleIdTokenValidator;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Service\AppleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserProvisioningRepository;

final class AuthenticateWithAppleServiceTest extends TestCase
{
    public function test_authenticates_registered_active_user_and_links_apple_identity(): void
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

        $service = new AuthenticateWithAppleService(
            new FakeAppleAuthorizationCodeExchanger('apple.header.payload'),
            new FakeAppleIdTokenValidator(new VerifiedAppleIdToken(
                subjectId: 'apple-sub-123',
                emailAddress: 'Admin@TriCab.com',
                emailVerified: true,
            )),
            new IdentityResolutionService($users),
        );

        $user = $service->execute('auth-code', 'expected-nonce');

        self::assertSame('admin@tricab.com', $user->emailAddress()->toString());
        self::assertNotNull($users->findByExternalIdentity(
            AppleExternalIdentityFactory::forSubject('apple-sub-123'),
        ));
    }

    public function test_rejects_unregistered_email(): void
    {
        $service = new AuthenticateWithAppleService(
            new FakeAppleAuthorizationCodeExchanger('apple.header.payload'),
            new FakeAppleIdTokenValidator(new VerifiedAppleIdToken(
                subjectId: 'apple-sub-404',
                emailAddress: 'unknown@example.com',
                emailVerified: true,
            )),
            new IdentityResolutionService(new InMemoryUserProvisioningRepository()),
        );

        $this->expectException(UserNotRegistered::class);
        $service->execute('auth-code', 'expected-nonce');
    }

    public function test_rejects_unverified_email(): void
    {
        $service = new AuthenticateWithAppleService(
            new FakeAppleAuthorizationCodeExchanger('apple.header.payload'),
            new FakeAppleIdTokenValidator(new VerifiedAppleIdToken(
                subjectId: 'apple-sub-uv',
                emailAddress: 'admin@tricab.com',
                emailVerified: false,
            )),
            new IdentityResolutionService(new InMemoryUserProvisioningRepository()),
        );

        $this->expectException(AppleIdTokenInvalid::class);
        $service->execute('auth-code', 'expected-nonce');
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

        $service = new AuthenticateWithAppleService(
            new FakeAppleAuthorizationCodeExchanger('apple.header.payload'),
            new FakeAppleIdTokenValidator(new VerifiedAppleIdToken(
                subjectId: 'apple-sub-disabled',
                emailAddress: 'disabled@tricab.com',
                emailVerified: true,
            )),
            new IdentityResolutionService($users),
        );

        $this->expectException(AuthenticationNotAllowed::class);
        $service->execute('auth-code', 'expected-nonce');
    }
}

final class FakeAppleAuthorizationCodeExchanger implements AppleAuthorizationCodeExchanger
{
    public function __construct(
        private readonly string $idToken,
    ) {
    }

    public function exchange(string $authorizationCode): string
    {
        unset($authorizationCode);

        return $this->idToken;
    }
}

final class FakeAppleIdTokenValidator implements AppleIdTokenValidator
{
    public function __construct(
        private readonly VerifiedAppleIdToken $verified,
    ) {
    }

    public function validate(string $idToken, string $expectedNonce): VerifiedAppleIdToken
    {
        unset($idToken, $expectedNonce);

        return $this->verified;
    }
}
