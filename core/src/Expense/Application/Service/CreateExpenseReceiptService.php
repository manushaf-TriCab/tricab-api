<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseManagerResolver;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseIdentity;
use TriCab\Core\Shared\Time\Clock;

final class CreateExpenseReceiptService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseManagerResolver $managers,
        private readonly ExpenseAuditRecorder $audit,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(ExpenseActor $actor, array $payload): ExpenseReceiptDetails
    {
        $this->rejectClientOwnership($payload);
        $profile = $this->access->requireActiveProfile($actor);

        $now = $this->clock->now();
        $managerUserId = $this->managers->resolveManagerUserId($profile);

        $attachment = null;
        if (isset($payload['attachmentUrl']) && is_string($payload['attachmentUrl']) && $payload['attachmentUrl'] !== '') {
            $attachment = ExpenseAttachmentRef::fromLegacyUrl(
                $payload['attachmentUrl'],
                isset($payload['attachmentFileName']) && is_string($payload['attachmentFileName'])
                    ? $payload['attachmentFileName']
                    : null,
            );
        }

        $receiptDate = null;
        if (isset($payload['receiptDate']) && is_string($payload['receiptDate']) && $payload['receiptDate'] !== '') {
            $receiptDate = new DateTimeImmutable($payload['receiptDate']);
        }

        $receipt = ExpenseReceipt::createDraft(
            publicId: ExpenseReceiptPublicId::generate(),
            ownerUserId: $actor->userId,
            vendorName: (string) ($payload['vendorName'] ?? ''),
            totalAmount: ExpenseMoney::fromString((string) ($payload['totalAmount'] ?? '0')),
            gstAmount: ExpenseMoney::fromString((string) ($payload['gstAmount'] ?? '0')),
            currency: ExpenseCurrency::fromString((string) ($payload['currency'] ?? 'AUD')),
            gstType: (string) ($payload['gstType'] ?? 'unknown'),
            receiptDate: $receiptDate,
            category: ExpenseCategory::fromLegacy(
                isset($payload['category']) ? (string) $payload['category'] : null,
                isset($payload['otherCategory']) ? (string) $payload['otherCategory'] : null,
            ),
            costCentre: (string) ($payload['costCentre'] ?? ''),
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            attachment: $attachment,
            now: $now,
            managerUserId: $managerUserId,
            legacy: LegacyExpenseIdentity::of(
                createdBy: $profile->legacyEmail(),
                managerLogin: $profile->managerLegacyLogin(),
            ),
            statementPeriod: isset($payload['statementPeriod']) ? (string) $payload['statementPeriod'] : null,
            customerCode: isset($payload['customerCode']) ? (string) $payload['customerCode'] : null,
            customerName: isset($payload['customerName']) ? (string) $payload['customerName'] : null,
            customerState: isset($payload['customerState']) ? (string) $payload['customerState'] : null,
            contactName: isset($payload['contactName']) ? (string) $payload['contactName'] : null,
            contactEmail: isset($payload['contactEmail']) ? (string) $payload['contactEmail'] : null,
            contactPhone: isset($payload['contactPhone']) ? (string) $payload['contactPhone'] : null,
        );

        $saved = $this->receipts->save($receipt);
        $this->audit->record($actor->userId, 'receipt_created', $saved->publicId());

        return $this->assembler->toDetails($saved);
    }

    /** @param array<string, mixed> $payload */
    private function rejectClientOwnership(array $payload): void
    {
        foreach (['ownerUserId', 'createdBy', 'submittedBy', 'ownerEmail', 'userId'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw ExpenseAccessDenied::ownershipForbidden();
            }
        }
    }
}