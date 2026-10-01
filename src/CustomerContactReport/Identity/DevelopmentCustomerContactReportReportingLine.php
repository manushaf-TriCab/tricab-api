<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Identity;

use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Deterministic creator -> manager -> upper manager line for the browser harness.
 * Production keeps GraphCustomerContactReportReportingLine.
 */
final class DevelopmentCustomerContactReportReportingLine implements CustomerContactReportReportingLine
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function managerOf(string $staffLogin): ?ResolvedCustomerContactAssignee
    {
        $managerEmail = CustomerContactE2eDirectory::managerEmail($staffLogin);
        if ($managerEmail === null) {
            return null;
        }

        try {
            $email = EmailAddress::fromString($managerEmail);
        } catch (InvalidIdentityValue) {
            return null;
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || !$user->status()->isActive()) {
            return null;
        }

        return new ResolvedCustomerContactAssignee(
            userId: $user->id()->toInt(),
            login: $user->emailAddress()->toString(),
            displayName: $user->displayName(),
            email: $user->emailAddress()->toString(),
        );
    }
}
