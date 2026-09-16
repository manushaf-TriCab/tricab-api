<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;

final class GetExpenseBankFeedService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor, string $publicId): array
    {
        $this->access->requireFinance($actor);
        $entry = $this->bankFeeds->findByPublicId(ExpenseBankFeedPublicId::fromString($publicId));
        if ($entry === null) {
            throw ExpenseBankFeedNotFound::forPublicId($publicId);
        }

        return ExpenseFinanceViews::bankEntry($entry);
    }
}
