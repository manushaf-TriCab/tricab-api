<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseManagerResolver;
use TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Lists ExpenseProfiles for Finance / TE Admin approver management.
 */
final class ListExpenseProfilesAdminService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseProfileAdminQuery $query,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseManagerResolver $managers,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(ExpenseActor $actor): array
    {
        $this->access->requireProfileAdministration($actor);

        $rows = [];
        foreach ($this->query->listActiveProfiles() as $row) {
            $profile = $this->profiles->findByUserId(UserId::fromInt((int) $row['userId']));
            $approverDisplay = null;
            $approverPublicId = null;
            $source = null;
            $hasApprover = false;

            if ($profile !== null) {
                $resolvedManagerId = $this->managers->resolveManagerUserId($profile);
                if ($resolvedManagerId !== null) {
                    $mgr = $this->users->findById($resolvedManagerId);
                    $approverDisplay = $mgr?->displayName();
                    $approverPublicId = $mgr?->publicId()->toString();
                    $legacyLogin = $profile->managerLegacyLogin();
                    $source = ($legacyLogin !== null && $legacyLogin !== '')
                        ? 'legacy_te'
                        : 'admin';
                    $hasApprover = true;
                }
            }

            $rows[] = [
                'employeePublicId' => (string) $row['publicId'],
                'employeeDisplayName' => (string) $row['displayName'],
                'employeeEmail' => (string) $row['email'],
                'legacyLogin' => (string) $row['legacyLogin'],
                'approverPublicId' => $approverPublicId,
                'approverDisplayName' => $approverDisplay,
                'approverSource' => $source,
                'hasApprover' => $hasApprover,
            ];
        }

        return $rows;
    }
}
