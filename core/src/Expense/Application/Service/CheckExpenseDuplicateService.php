<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Service\ExpenseVendorNormalizer;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;

final class CheckExpenseDuplicateService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseReceiptAssembler $assembler,
    ) {
    }

    /**
     * Warning only — never blocks save/submit.
     *
     * @return list<array<string, mixed>>
     */
    public function execute(ExpenseActor $actor, string $vendorName, ?string $receiptDate, ?string $excludePublicId = null): array
    {
        $this->access->requireActiveProfile($actor);
        $date = $receiptDate !== null && $receiptDate !== '' ? new DateTimeImmutable($receiptDate) : null;
        if ($date === null || ExpenseVendorNormalizer::normalize($vendorName) === '') {
            return [];
        }

        $exclude = $excludePublicId !== null && $excludePublicId !== ''
            ? ExpenseReceiptPublicId::fromString($excludePublicId)
            : null;

        $matches = [];
        foreach ($this->receipts->listOwnedBy($actor->userId) as $receipt) {
            if ($exclude !== null && $receipt->publicId()->equals($exclude)) {
                continue;
            }
            if ($receipt->receiptDate()?->format('Y-m-d') !== $date->format('Y-m-d')) {
                continue;
            }
            if (!ExpenseVendorNormalizer::isFuzzyMatch($vendorName, $receipt->vendorName())) {
                continue;
            }
            $details = $this->assembler->toDetails($receipt);
            $matches[] = [
                'publicId' => $details->publicId,
                'vendorName' => $details->vendorName,
                'totalAmount' => $details->totalAmount,
                'currency' => $details->currency,
                'receiptDate' => $details->receiptDate,
                'status' => $details->status,
            ];
            if (count($matches) >= 5) {
                break;
            }
        }

        return $matches;
    }
}
