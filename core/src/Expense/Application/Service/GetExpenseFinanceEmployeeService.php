<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class GetExpenseFinanceEmployeeService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseBankFeedRepository $bankFeeds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor, int|string $userIdOrEmail): array
    {
        $this->access->requireFinance($actor);

        $profile = null;
        $email = null;
        $userId = null;

        if (is_int($userIdOrEmail) || (is_string($userIdOrEmail) && ctype_digit($userIdOrEmail))) {
            $userId = UserId::fromInt((int) $userIdOrEmail);
            $profile = $this->profiles->findByUserId($userId);
            $email = $profile?->legacyEmail();
        } else {
            $email = strtolower(trim((string) $userIdOrEmail));
            if ($email === '' || !str_contains($email, '@')) {
                throw InvalidExpenseValue::for('userIdOrEmail', (string) $userIdOrEmail);
            }
            $profile = $this->profiles->findByLegacyEmail($email);
            $userId = $profile?->userId();
        }

        $receiptViews = [];
        if ($userId !== null) {
            foreach ($this->receipts->listOwnedBy($userId, false) as $receipt) {
                $receiptViews[] = ExpenseFinanceViews::receipt($receipt);
            }
        }

        $bankViews = [];
        $needle = $email ?? $profile?->legacyEmail();
        if ($needle !== null) {
            $needle = strtolower($needle);
            foreach ($this->bankFeeds->listAll() as $entry) {
                if ($entry->cardholderEmail() === $needle) {
                    $bankViews[] = ExpenseFinanceViews::bankEntry($entry);
                }
            }
        }

        return [
            'userId' => $userId?->toInt(),
            'email' => $needle,
            'profile' => $profile === null ? null : [
                'userId' => $profile->userId()->toInt(),
                'legacyEmail' => $profile->legacyEmail(),
                'legacyLogin' => $profile->legacyLogin(),
                'isActive' => $profile->isActive(),
                'isFinance' => $profile->isFinance(),
            ],
            'receipts' => $receiptViews,
            'bankEntries' => $bankViews,
        ];
    }
}
