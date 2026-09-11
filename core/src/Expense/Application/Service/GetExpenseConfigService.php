<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags;
use TriCab\Core\Expense\Application\ExpenseCatalog;

final class GetExpenseConfigService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ?ExpenseFeatureFlags $features = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor): array
    {
        $this->access->requireActiveProfile($actor);

        return ExpenseCatalog::clientConfig($this->features);
    }
}
