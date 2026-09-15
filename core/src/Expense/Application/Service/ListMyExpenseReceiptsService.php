<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseReadRepository;
use TriCab\Core\Expense\Module;

final class ListMyExpenseReceiptsService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly LegacyExpenseReadRepository $legacyReceipts,
        private readonly LegacyExpenseReceiptAssembler $legacyAssembler,
        private readonly LegacyExpenseIdentityResolver $legacyIdentity,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    /**
     * @return list<ExpenseReceiptDetails>
     */
    public function execute(ExpenseActor $actor, ?int $requestedOtherUserId = null): array
    {
        if (!$actor->hasPermission(Module::PERMISSION_ACCESS)) {
            throw ExpenseAccessDenied::permissionRequired();
        }

        if ($requestedOtherUserId !== null && $requestedOtherUserId !== $actor->userId->toInt()) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        // Profile is operational metadata for legacy mapping — not an auth gate for listing.
        $profile = $this->profiles->findByUserId($actor->userId);

        $items = [];
        $migratedLegacyIds = [];
        foreach ($this->receipts->listOwnedBy($actor->userId) as $receipt) {
            $details = $this->assembler->toDetails($receipt);
            $items[] = $details;
            $legacyId = $receipt->legacy()->legacyReceiptId();
            if ($legacyId !== null && $legacyId > 0) {
                $migratedLegacyIds[$legacyId] = true;
            }
        }

        if ($this->legacyReadEnabled) {
            $ownerKeys = $this->legacyIdentity->ownerKeys($profile);
            if ($ownerKeys !== []) {
                foreach ($this->legacyReceipts->listForOwnerKeys($ownerKeys) as $row) {
                    $legacyId = (int) ($row['id'] ?? 0);
                    if ($legacyId > 0 && isset($migratedLegacyIds[$legacyId])) {
                        continue;
                    }
                    $items[] = $this->legacyAssembler->toDetails($row);
                }
            }
        }

        usort(
            $items,
            static function (ExpenseReceiptDetails $a, ExpenseReceiptDetails $b): int {
                $dateA = $a->receiptDate ?? substr($a->createdAt, 0, 10);
                $dateB = $b->receiptDate ?? substr($b->createdAt, 0, 10);
                $cmp = strcmp($dateB, $dateA);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp($b->publicId, $a->publicId);
            },
        );

        return $items;
    }
}
