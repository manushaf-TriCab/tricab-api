<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Handler;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Application\Service\IdentityResolutionService;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
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

final class ResolveAuthenticatedUserHandlerTest extends TestCase
{
    private const TENANT_ID = '72f988bf-86f1-41af-91ab-2d7cd011db47';
    private const SUBJECT_ID = 'oid-abc-123';

    public function test_resolves_linked_active_user_to_authenticated_user_dto(): void
    {
        $repository = new InMemoryUserProvisioningRepository();
        $user = $this->user(UserStatus::active());
        $registered = $repository->register(
            emailAddress: $user->emailAddress(),
            displayName: $user->displayName(),
            status: $user->status(),
            roles: $user->roles(),
            permissions: $user->permissions(),
            identity: $this->externalIdentity(),
        );

        $handler = new ResolveAuthenticatedUserHandler(new IdentityResolutionService($repository));
        $result = $handler->handle($this->verifiedIdentity());

        self::assertSame($registered->id()->toInt(), $result->userId);
        self::assertSame($registered->publicId()->toString(), $result->publicId);
        self::assertSame('jane@example.com', $result->emailAddress);
    }

    public function test_resolves_by_verified_email_when_external_identity_missing(): void
    {
        $repository = new InMemoryUserProvisioningRepository();
        $repository->register(
            emailAddress: EmailAddress::fromString('jane@example.com'),
            displayName: 'Jane Doe',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('STAFF')],
            permissions: [PermissionCode::fromString('ORDERS:READ')],
            identity: EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed@example.com')),
        );

        $handler = new ResolveAuthenticatedUserHandler(new IdentityResolutionService($repository));
        $result = $handler->handle(new VerifiedIdentity(
            provider: ExternalProvider::MICROSOFT_ENTRA,
            subjectId: 'oid-without-link',
            tenantId: self::TENANT_ID,
            verifiedEmail: 'jane@example.com',
        ));

        self::assertSame('jane@example.com', $result->emailAddress);
    }

    public function test_rejects_unregistered_identity(): void
    {
        $handler = new ResolveAuthenticatedUserHandler(
            new IdentityResolutionService(new InMemoryUserProvisioningRepository()),
        );

        $this->expectException(UserNotRegistered::class);
        $handler->handle($this->verifiedIdentity());
    }

    public function test_rejects_pending_user(): void
    {
        $repository = new InMemoryUserProvisioningRepository();
        $repository->register(
            emailAddress: EmailAddress::fromString('jane@example.com'),
            displayName: 'Jane Doe',
            status: UserStatus::pending(),
            roles: [RoleCode::fromString('STAFF')],
            permissions: [PermissionCode::fromString('ORDERS:READ')],
            identity: $this->externalIdentity(),
        );
        $handler = new ResolveAuthenticatedUserHandler(new IdentityResolutionService($repository));

        $this->expectException(AuthenticationNotAllowed::class);
        $handler->handle($this->verifiedIdentity());
    }

    public function test_rejects_disabled_user(): void
    {
        $repository = new InMemoryUserProvisioningRepository();
        $repository->register(
            emailAddress: EmailAddress::fromString('jane@example.com'),
            displayName: 'Jane Doe',
            status: UserStatus::disabled(),
            roles: [RoleCode::fromString('STAFF')],
            permissions: [PermissionCode::fromString('ORDERS:READ')],
            identity: $this->externalIdentity(),
        );
        $handler = new ResolveAuthenticatedUserHandler(new IdentityResolutionService($repository));

        $this->expectException(AuthenticationNotAllowed::class);
        $handler->handle($this->verifiedIdentity());
    }

    public function test_rejects_invalid_verified_identity_values(): void
    {
        $handler = new ResolveAuthenticatedUserHandler(
            new IdentityResolutionService(new InMemoryUserProvisioningRepository()),
        );

        $this->expectException(InvalidIdentityValue::class);
        $handler->handle(new VerifiedIdentity(
            provider: 'not-a-provider',
            subjectId: self::SUBJECT_ID,
            tenantId: self::TENANT_ID,
        ));
    }

    private function verifiedIdentity(): VerifiedIdentity
    {
        return new VerifiedIdentity(
            provider: ExternalProvider::MICROSOFT_ENTRA,
            subjectId: self::SUBJECT_ID,
            tenantId: self::TENANT_ID,
            verifiedEmail: 'jane@example.com',
        );
    }

    private function externalIdentity(): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::microsoftEntra(),
            ExternalSubjectId::fromString(self::SUBJECT_ID),
            ExternalTenantId::fromString(self::TENANT_ID),
        );
    }

    private function user(UserStatus $status): User
    {
        return new User(
            UserId::fromInt(42),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            EmailAddress::fromString('jane@example.com'),
            'Jane Doe',
            $status,
            [RoleCode::fromString('STAFF')],
            [PermissionCode::fromString('ORDERS:READ')],
        );
    }
}
