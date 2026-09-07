<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;

final class ListExpenseApprovalsService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseReceiptAssembler $assembler,
    ) {
    }

    /**
     * Manager queue — identity derived from authenticated actor only.
     *
     * @return list<ExpenseReceiptDetails>
     */
    public function execute(ExpenseActor $actor): array
    {
        $this->access->requireActiveProfile($actor);

        $items = [];
        foreach ($this->receipts->listPendingForManager($actor->userId) as $receipt) {
            $items[] = $this->assembler->toDetails($receipt);
        }

        return $items;
    }
}