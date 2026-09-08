<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\VerifiedAppleIdToken;
use TriCab\Core\Identity\Application\Exception\AppleIdTokenInvalid;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Service\AuthenticateWithAppleNativeService;
use TriCab\Core\Identity\Application\Service\IdentityResolutionService;
use TriCab\Core\Identity\Contract\AppleNativeIdTokenValidator;
use TriCab\Core\Identity\Domain\Service\AppleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserProvisioningRepository;

final class AuthenticateWithAppleNativeServiceTest extends TestCase
{
    public function test_resolves_linked_user_without_email_on_subsequent_login(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $email = EmailAddress::fromString('admin@tricab.com');
        $user = $users->register(
            emailAddress: $email,
            displayName: 'Admin',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('ADMINISTRATOR')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(
                EmailAddress::fromString('seed-admin@tricab.com'),
            ),
        );
        $users->linkExternalIdentity($user, AppleExternalIdentityFactory::forSubject('apple-sub-123'));

        $service = new AuthenticateWithAppleNativeService(
            new FakeAppleNativeIdTokenValidator(new VerifiedAppleIdToken(
                subjectId: 'apple-sub-123',
                emailAddress: '',
                emailVerified: true,
            )),
            new IdentityResolutionService($users),
        );

        $result = $service->execute('native.identity.token');
        self::assertTrue($result->id()->equals($user->id()));
    }

    public function test_rejects_unverified_email_when_present(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $service = new AuthenticateWithAppleNativeService(
            new FakeAppleNativeIdTokenValidator(new VerifiedAppleIdToken(
                subjectId: 'apple-sub-2',
                emailAddress: 'person@tricab.com',
                emailVerified: false,
            )),
            new IdentityResolutionService($users),
        );

        $this->expectException(AppleIdTokenInvalid::class);
        $service->execute('native.identity.token');
    }

    public function test_unlinked_subject_without_email_is_not_registered(): void
    {
        $users = new InMemoryUserProvisioningRepository();
        $service = new AuthenticateWithAppleNativeService(
            new FakeAppleNativeIdTokenValidator(new VerifiedAppleIdToken(
                subjectId: 'unknown-sub',
                emailAddress: '',
                emailVerified: true,
            )),
            new IdentityResolutionService($users),
        );

        $this->expectException(UserNotRegistered::class);
        $service->execute('native.identity.token');
    }
}

final class FakeAppleNativeIdTokenValidator implements AppleNativeIdTokenValidator
{
    public function __construct(private readonly VerifiedAppleIdToken $verified)
    {
    }

    public function validateNative(string $idToken): VerifiedAppleIdToken
    {
        unset($idToken);

        return $this->verified;
    }
}
