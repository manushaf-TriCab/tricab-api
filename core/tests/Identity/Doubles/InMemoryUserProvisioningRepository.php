<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Application\DTO\VerifiedEmailOwner;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddressPurpose;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

class InMemoryUserProvisioningRepository implements UserProvisioningRepository
{
    private readonly InMemoryUserRepository $inner;

    private int $nextId = 1;

    private readonly UuidV4Generator $uuids;

    public function __construct(?InMemoryUserRepository $inner = null)
    {
        $this->inner = $inner ?? new InMemoryUserRepository();
        $this->uuids = new UuidV4Generator();
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        return $this->inner->findByEmail($emailAddress);
    }

    /**
     * @return list<User>
     */
    public function findAllByEmail(EmailAddress $emailAddress): array
    {
        return $this->inner->findAllByEmail($emailAddress);
    }

    /**
     * @var array<int, array<string, string>> userId => normalizedEmail => purpose
     * Extra verified addresses beyond the user's primary one.
     */
    private array $verifiedAddresses = [];

    /**
     * Records an additional verified address for a user, so tests can build
     * the case this whole change exists for: one person, a personal address
     * and a corporate one.
     */
    public function addVerifiedAddress(UserId $userId, EmailAddress $emailAddress, EmailAddressPurpose $purpose): void
    {
        $this->verifiedAddresses[$userId->toInt()][$this->normalize($emailAddress)] = $purpose->toString();
    }

    /**
     * @return list<VerifiedEmailOwner>
     */
    public function findAllByVerifiedEmail(EmailAddress $emailAddress): array
    {
        $normalized = $this->normalize($emailAddress);

        foreach ($this->verifiedAddresses as $userId => $addresses) {
            if (!isset($addresses[$normalized])) {
                continue;
            }

            $user = $this->inner->findById(UserId::fromInt($userId));
            if ($user !== null) {
                return [new VerifiedEmailOwner($user, EmailAddressPurpose::fromString($addresses[$normalized]))];
            }
        }

        // Unclassified: the primary address, with no purpose recorded, so the
        // binding policy does not apply and resolution behaves as it always has.
        return array_map(
            static fn (User $user): VerifiedEmailOwner => new VerifiedEmailOwner($user, null),
            $this->findAllByEmail($emailAddress),
        );
    }

    public function hasVerifiedAddress(UserId $userId, EmailAddress $emailAddress): bool
    {
        $normalized = $this->normalize($emailAddress);

        if (isset($this->verifiedAddresses[$userId->toInt()][$normalized])) {
            return true;
        }

        $user = $this->inner->findById($userId);

        return $user !== null && $this->normalize($user->emailAddress()) === $normalized;
    }

    private function normalize(EmailAddress $emailAddress): string
    {
        return strtolower(trim($emailAddress->toString()));
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        return $this->inner->findByExternalIdentity($identity);
    }

    /**
     * @param list<RoleCode> $roles
     * @param list<PermissionCode> $permissions
     */
    public function register(
        EmailAddress $emailAddress,
        string $displayName,
        UserStatus $status,
        array $roles,
        array $permissions,
        ExternalIdentity $identity,
    ): User {
        $user = new User(
            UserId::fromInt($this->nextId),
            UserPublicId::fromString($this->uuids->generate()->toString()),
            $emailAddress,
            $displayName,
            $status,
            $roles,
            $permissions,
        );
        ++$this->nextId;

        $this->inner->save($user, $identity);

        return $user;
    }

    public function linkExternalIdentity(User $user, ExternalIdentity $identity): void
    {
        $this->inner->save($user, $identity);
    }

    public function findActiveProviderSubjectForUser(UserId $userId, ExternalProvider $provider): ?ExternalSubjectId
    {
        return $this->inner->findActiveProviderSubjectForUser($userId, $provider);
    }

    public function inner(): InMemoryUserRepository
    {
        return $this->inner;
    }
}
