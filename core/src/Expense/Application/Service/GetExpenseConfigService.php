<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use Throwable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags;
use TriCab\Core\Expense\Application\ExpenseCatalog;
use TriCab\Core\Expense\Contract\ExpenseCostCentreOptionsPort;
use TriCab\Core\Expense\Contract\ExpenseManagerResolver;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Contract\UserRepository;

final class GetExpenseConfigService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseCostCentreOptionsPort $costCentres,
        private readonly ?ExpenseFeatureFlags $features = null,
        private readonly ?ExpenseManagerResolver $managers = null,
        private readonly ?UserRepository $users = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor): array
    {
        $profile = $this->access->requireActiveProfile($actor);

        $available = $this->costCentres->isAvailable();
        $options = [];
        if ($available) {
            try {
                foreach ($this->costCentres->listActiveLabels() as $label) {
                    $options[] = ['code' => $label, 'name' => $label];
                }
            } catch (Throwable) {
                $available = false;
                $options = [];
            }
        }

        $config = ExpenseCatalog::clientConfig($this->features, $options, $available);
        $config['approver'] = $this->approverSnapshot($profile);

        return $config;
    }

    /**
     * @return array{assigned: bool, displayName: string|null}
     */
    private function approverSnapshot(ExpenseProfile $profile): array
    {
        if ($this->managers === null) {
            return ['assigned' => false, 'displayName' => null];
        }
        $managerId = $this->managers->resolveManagerUserId($profile);
        if ($managerId === null) {
            return ['assigned' => false, 'displayName' => null];
        }
        $display = null;
        if ($this->users !== null) {
            $display = $this->users->findById($managerId)?->displayName();
        }

        return [
            'assigned' => true,
            'displayName' => $display,
        ];
    }
}
