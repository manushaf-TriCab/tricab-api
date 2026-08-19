<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * In-memory UserRepository for unit and application tests only.
 */
final class InMemoryUserRepository implements UserRepository
{
    /** @var array<int, User> */
    private array $usersById = [];

    /** @var array<string, int> */
    private array $userIdsByEmail = [];

    /** @var array<string, int> */
    private array $userIdsByExternalIdentity = [];

    /** @var array<int, array<string, string>> */
    private array $providerSubjectsByUserId = [];

    public function save(User $user, ?ExternalIdentity $externalIdentity = null): void
    {
        $userId = $user->id()->toInt();
        $this->usersById[$userId] = $user;
        $this->userIdsByEmail[$user->emailAddress()->toString()] = $userId;

        if ($externalIdentity !== null) {
            $this->userIdsByExternalIdentity[$this->externalIdentityKey($externalIdentity)] = $userId;
            $this->providerSubjectsByUserId[$userId][$externalIdentity->provider()->toString()]
                = $externalIdentity->subjectId()->toString();
        }
    }

    public function findById(UserId $userId): ?User
    {
        return $this->usersById[$userId->toInt()] ?? null;
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        $userId = $this->userIdsByEmail[$emailAddress->toString()] ?? null;

        if ($userId === null) {
            return null;
        }

        return $this->usersById[$userId] ?? null;
    }

    /**
     * @return list<User>
     */
    public function findAllByEmail(EmailAddress $emailAddress): array
    {
        $matches = [];
        foreach ($this->usersById as $user) {
            if ($user->emailAddress()->equals($emailAddress)) {
                $matches[] = $user;
            }
        }

        return $matches;
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        $userId = $this->userIdsByExternalIdentity[$this->externalIdentityKey($identity)] ?? null;

        if ($userId === null) {
            return null;
        }

        return $this->usersById[$userId] ?? null;
    }

    public function findActiveProviderSubjectForUser(UserId $userId, ExternalProvider $provider): ?ExternalSubjectId
    {
        $subject = $this->providerSubjectsByUserId[$userId->toInt()][$provider->toString()] ?? null;

        return $subject !== null ? ExternalSubjectId::fromString($subject) : null;
    }

    /**
     * @return list<string>
     */
    public function externalIdentityKeys(): array
    {
        return array_keys($this->userIdsByExternalIdentity);
    }

    private function externalIdentityKey(ExternalIdentity $identity): string
    {
        return implode('|', [
            $identity->provider()->toString(),
            $identity->subjectId()->toString(),
            $identity->tenantId()->toString(),
        ]);
    }
}
