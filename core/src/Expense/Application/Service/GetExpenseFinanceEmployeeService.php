<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceBankFilters;
use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceFilters;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class GetExpenseFinanceEmployeeService
{
    /** Upper bound for the legacy bank slice loaded for one cardholder. */
    private const LEGACY_BANK_CAP = 1000;

    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly LegacyExpenseFinanceReadRepository $legacyFinance,
        private readonly LegacyExpenseFinanceReceiptAssembler $legacyAssembler,
        private readonly LegacyExpenseIdentityResolver $legacyIdentity,
        private readonly LegacyExpenseFinanceBankReadRepository $legacyBank,
        private readonly LegacyExpenseFinanceBankAssembler $legacyBankAssembler,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        ExpenseActor $actor,
        int|string $userIdOrEmail,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $this->access->requireFinance($actor);

        $profile = null;
        $email = null;
        $userId = null;

        if (is_int($userIdOrEmail)) {
            $userId = UserId::fromInt($userIdOrEmail);
            $profile = $this->profiles->findByUserId($userId);
            $email = $profile?->legacyEmail();
        } elseif (ctype_digit($userIdOrEmail)) {
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

        $from = $dateFrom !== null && trim($dateFrom) !== '' ? substr(trim($dateFrom), 0, 10) : null;
        $to = $dateTo !== null && trim($dateTo) !== '' ? substr(trim($dateTo), 0, 10) : null;

        $receiptViews = [];
        $migratedLegacyIds = [];
        if ($userId !== null) {
            foreach ($this->receipts->listOwnedBy($userId, false) as $receipt) {
                if ($receipt->status()->isDraft()) {
                    continue;
                }
                $receiptDate = $receipt->receiptDate()?->format('Y-m-d');
                if ($from !== null && $receiptDate !== null && $receiptDate < $from) {
                    continue;
                }
                if ($to !== null && $receiptDate !== null && $receiptDate > $to) {
                    continue;
                }
                $legacyId = $receipt->legacy()->legacyReceiptId();
                if ($legacyId !== null && $legacyId > 0) {
                    $migratedLegacyIds[$legacyId] = true;
                }
                $manager = $receipt->managerUserId() !== null
                    ? $this->profiles->findByUserId($receipt->managerUserId())
                    : null;
                $receiptViews[] = ExpenseFinanceViews::receipt($receipt, [
                    'employeeEmail' => $email ?? $profile?->legacyEmail(),
                    'employeeDisplayName' => $email ?? $profile?->legacyEmail(),
                    'managerDisplayName' => $manager?->legacyEmail(),
                    'managerLogin' => $manager?->legacyLogin(),
                ]);
            }
        }

        if ($this->legacyReadEnabled) {
            $ownerKeys = $this->legacyIdentity->ownerKeys($profile);
            if ($ownerKeys === [] && $email !== null) {
                $ownerKeys = [strtolower($email)];
                $login = explode('@', strtolower($email))[0];
                if ($login !== '') {
                    $ownerKeys[] = $login;
                    $ownerKeys[] = $login . '@tricab.com';
                }
            }
            if ($ownerKeys !== []) {
                $filters = new LegacyExpenseFinanceFilters(
                    dateFrom: $from,
                    dateTo: $to,
                    excludeDraft: true,
                    ownerKeys: $ownerKeys,
                    limit: 1000,
                    offset: 0,
                );
                foreach ($this->legacyFinance->listFinance($filters) as $row) {
                    $legacyId = MixedValueCoercion::intValue($row['id'] ?? 0);
                    if ($legacyId > 0 && isset($migratedLegacyIds[$legacyId])) {
                        continue;
                    }
                    $receiptViews[] = $this->legacyAssembler->toFinanceView($row, [
                        'ownerUserId' => $userId?->toInt(),
                        'employeeEmail' => $email,
                    ]);
                }
            }
        }

        $bankViews = [];
        $migratedBankIds = [];
        $needle = $email ?? $profile?->legacyEmail();
        if ($needle !== null) {
            $needle = strtolower($needle);
            foreach ($this->bankFeeds->listAll() as $entry) {
                if ($entry->cardholderEmail() === $needle) {
                    $legacyBankId = $entry->legacyBankFeedId();
                    if ($legacyBankId !== null && $legacyBankId > 0) {
                        $migratedBankIds[$legacyBankId] = true;
                    }
                    $tx = $entry->transactionDate()?->format('Y-m-d');
                    if ($from !== null && $tx !== null && $tx < $from) {
                        continue;
                    }
                    if ($to !== null && $tx !== null && $tx > $to) {
                        continue;
                    }
                    $bankViews[] = ExpenseFinanceViews::bankEntry($entry);
                }
            }
        }

        if ($this->legacyReadEnabled && $needle !== null) {
            $bankRows = ListExpenseFinanceBankTransactionsService::pageRows(
                $this->legacyBank,
                new LegacyExpenseFinanceBankFilters(
                    dateFrom: $from,
                    dateTo: $to,
                    cardholderEmail: $needle,
                ),
                self::LEGACY_BANK_CAP,
            );
            foreach ($bankRows as $row) {
                $legacyBankId = MixedValueCoercion::intValue($row['id'] ?? 0);
                if ($legacyBankId > 0 && isset($migratedBankIds[$legacyBankId])) {
                    continue;
                }
                $bankViews[] = $this->legacyBankAssembler->toFinanceView($row);
            }
        }

        $buckets = [
            'matched' => [],
            'needsReceipt' => [],
            'needsMatch' => [],
            'pendingApproval' => [],
            'exceptions' => [],
            'other' => [],
        ];
        foreach ($receiptViews as $view) {
            $bucket = $this->classifyReceipt($view);
            $buckets[$bucket][] = $view;
        }
        foreach ($bankViews as $bank) {
            if ($bank['needsReceipt'] === true) {
                $buckets['needsReceipt'][] = $bank;
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
            'currencyTotals' => ExpenseFinanceViews::currencyBuckets($receiptViews),
            'receipts' => $receiptViews,
            'bankEntries' => $bankViews,
            'buckets' => [
                'matched' => $buckets['matched'],
                'needsReceipt' => $buckets['needsReceipt'],
                'needsMatch' => $buckets['needsMatch'],
                'pendingApproval' => $buckets['pendingApproval'],
                'exceptions' => $buckets['exceptions'],
            ],
            'legacyReadEnabled' => $this->legacyReadEnabled,
        ];
    }

    /**
     * @param array<string, mixed> $view
     */
    private function classifyReceipt(array $view): string
    {
        $status = MixedValueCoercion::stringValue($view['status'] ?? '');
        $resolution = strtolower(MixedValueCoercion::stringValue($view['resolution'] ?? ''));
        $bankId = $view['bankFeedEntryId'] ?? null;

        if ($status === 'pending' || MixedValueCoercion::stringValue($view['managerStatus'] ?? '') === 'pending') {
            return 'pendingApproval';
        }
        if (in_array($resolution, [
            Module::RESOLUTION_MARKED_CASH,
            Module::RESOLUTION_EXCEPTION,
            Module::RESOLUTION_FORCE_REJECTED,
            Module::RESOLUTION_FORCE_APPROVED,
        ], true)) {
            return 'exceptions';
        }
        if ($bankId !== null && $bankId !== '') {
            return 'matched';
        }
        if ($status !== 'draft' && ($resolution === '' || $resolution === Module::RESOLUTION_CHASED)) {
            return 'needsMatch';
        }

        return 'other';
    }
}
