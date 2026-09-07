<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class ListMyExpenseReceiptsService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseReceiptAssembler $assembler,
    ) {
    }

    /**
     * @return list<ExpenseReceiptDetails>
     */
    public function execute(ExpenseActor $actor, ?int $requestedOtherUserId = null): array
    {
        $this->access->requireActiveProfile($actor);

        if ($requestedOtherUserId !== null && $requestedOtherUserId !== $actor->userId->toInt()) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        $items = [];
        foreach ($this->receipts->listOwnedBy($actor->userId) as $receipt) {
            $items[] = $this->assembler->toDetails($receipt);
        }

        return $items;
    }
}