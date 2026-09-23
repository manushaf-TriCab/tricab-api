<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;

/**
 * Test double and the container default. Accounts are seeded with
 * {@see withAccount()}; created ones are kept in {@see $created} so a test can
 * read back exactly what would have been made.
 */
final class InMemoryOnboardingAccountDirectory implements OnboardingAccountDirectory
{
    /** @var array<string, int> */
    private array $byEmail = [];

    /** @var array<int, bool> */
    private array $canSignIn = [];

    /** @var list<array{userId: int, email: string, displayName: string, actorUserId: int}> */
    public array $created = [];

    private int $nextUserId = 5000;

    public function withAccount(int $userId, string $email, bool $canSignIn): void
    {
        $this->byEmail[strtolower($email)] = $userId;
        $this->canSignIn[$userId] = $canSignIn;
    }

    public function findUserIdByEmail(EmailAddress $emailAddress): ?int
    {
        return $this->byEmail[strtolower($emailAddress->toString())] ?? null;
    }

    public function createGuestAccount(ActorContext $actor, EmailAddress $emailAddress, string $displayName): int
    {
        $userId = $this->nextUserId++;
        $this->withAccount($userId, $emailAddress->toString(), true);
        $this->created[] = ['userId' => $userId, 'email' => $emailAddress->toString(), 'displayName' => $displayName, 'actorUserId' => $actor->userId];

        return $userId;
    }

    public function canSignIn(int $userId): bool
    {
        return $this->canSignIn[$userId] ?? false;
    }
}
