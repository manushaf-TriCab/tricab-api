<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Identity;

use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Staff lookup for Customer Contact assignment.
 *
 * Reuses identity UserRepository. There is no separate employee table in the
 * new API, and unmatched legacy logins are not accepted.
 */
final class IdentityCustomerContactReportAssigneeDirectory implements CustomerContactReportAssigneeDirectory
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function findActiveStaffByLogin(string $login): ?ResolvedCustomerContactAssignee
    {
        try {
            $email = EmailAddress::fromString($login);
        } catch (InvalidIdentityValue) {
            return null;
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || !$user->status()->isActive() || !$this->isStaff($user->roles())) {
            return null;
        }

        return new ResolvedCustomerContactAssignee(
            userId: $user->id()->toInt(),
            login: $user->emailAddress()->toString(),
            displayName: $user->displayName(),
            email: $user->emailAddress()->toString(),
        );
    }

    /**
     * @param list<\TriCab\Core\Identity\Domain\ValueObject\RoleCode> $roles
     */
    private function isStaff(array $roles): bool
    {
        foreach ($roles as $role) {
            $code = $role->toString();
            if ($code === ProtectedIdentityCatalog::ROLE_EMPLOYEE || $code === ProtectedIdentityCatalog::ROLE_ADMINISTRATOR) {
                return true;
            }
        }

        return false;
    }
}
