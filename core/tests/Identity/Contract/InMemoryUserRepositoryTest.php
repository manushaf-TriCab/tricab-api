<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Contract;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserRepository;

final class InMemoryUserRepositoryTest extends TestCase
{
    public function test_find_by_id_returns_saved_user(): void
    {
        $repository = new InMemoryUserRepository();
        $user = $this->user(id: 10, email: 'jane@example.com');
        $repository->save($user);

        $found = $repository->findById(UserId::fromInt(10));

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($user->id()));
        self::assertNull($repository->findById(UserId::fromInt(99)));
    }

    public function test_find_by_email_returns_saved_user(): void
    {
        $repository = new InMemoryUserRepository();
        $user = $this->user(id: 11, email: 'jane@example.com');
        $repository->save($user);

        $found = $repository->findByEmail(EmailAddress::fromString('JANE@EXAMPLE.COM'));

        self::assertNotNull($found);
        self::assertTrue($found->emailAddress()->equals($user->emailAddress()));
        self::assertNull($repository->findByEmail(EmailAddress::fromString('other@example.com')));
    }

    public function test_find_by_external_identity_returns_linked_user(): void
    {
        $repository = new InMemoryUserRepository();
        $user = $this->user(id: 12, email: 'linked@example.com');
        $identity = $this->externalIdentity('oid-abc');
        $repository->save($user, $identity);

        $found = $repository->findByExternalIdentity($identity);
        $unlinked = $repository->findByExternalIdentity($this->externalIdentity('oid-other'));

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($user->id()));
        self::assertNull($unlinked);
    }

    public function test_save_without_external_identity_does_not_index_external_lookup(): void
    {
        $repository = new InMemoryUserRepository();
        $user = $this->user(id: 13, email: 'nolink@example.com');
        $repository->save($user);

        self::assertNull($repository->findByExternalIdentity($this->externalIdentity('oid-abc')));
        self::assertNotNull($repository->findById(UserId::fromInt(13)));
    }

    private function user(int $id, string $email): User
    {
        return new User(
            UserId::fromInt($id),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            EmailAddress::fromString($email),
            'Jane Doe',
            UserStatus::active(),
            [RoleCode::fromString('STAFF')],
            [PermissionCode::fromString('ORDERS:READ')],
        );
    }

    private function externalIdentity(string $subject): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::microsoftEntra(),
            ExternalSubjectId::fromString($subject),
            ExternalTenantId::fromString('72f988bf-86f1-41af-91ab-2d7cd011db47'),
        );
    }
}
