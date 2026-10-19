<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use Throwable;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Npr\Domain\NprPerson;

/**
 * Looks up the platform users a request names (manager, TSD owner, signatory).
 * People are always identity users, never free-typed addresses.
 */
final class NprPeople
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /** The user by id when given, else by email; null when there is none. */
    public function find(?int $userId, ?string $email): ?User
    {
        try {
            if ($userId !== null) {
                return $this->users->findById(UserId::fromInt($userId));
            }
            if ($email !== null && trim($email) !== '') {
                return $this->users->findByEmail(EmailAddress::fromString($email));
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** An active user holding the permission, or null. */
    public function findActiveWith(?int $userId, ?string $email, string $permission): ?User
    {
        $user = $this->find($userId, $email);

        return $user !== null && $user->status()->isActive() && $user->hasPermission(PermissionCode::fromString($permission)) ? $user : null;
    }

    public function isActive(int $userId): bool
    {
        $user = $this->find($userId, null);

        return $user !== null && $user->status()->isActive();
    }

    public static function person(User $user): NprPerson
    {
        return new NprPerson($user->id()->toInt(), $user->emailAddress()->toString(), $user->displayName());
    }
}
