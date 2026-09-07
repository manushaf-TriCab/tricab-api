<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseStatusTransition;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptStatus;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseIdentity;
use TriCab\Core\Expense\Domain\ValueObject\ManagerApprovalStatus;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Aggregate root — receipt-centric (no ExpenseClaim / line items).
 */
final class ExpenseReceipt
{
    private function __construct(
        private ?ExpenseReceiptId $id,
        private readonly ExpenseReceiptPublicId $publicId,
        private UserId $ownerUserId,
        private ?UserId $submittedByUserId,
        private ?UserId $managerUserId,
        private ExpenseReceiptStatus $status,
        private ManagerApprovalStatus $managerStatus,
        private ?string $managerReason,
        private string $vendorName,
        private ExpenseMoney $totalAmount,
        private ExpenseMoney $gstAmount,
        private ExpenseCurrency $currency,
        private string $gstType,
        private ?DateTimeImmutable $receiptDate,
        private ExpenseCategory $category,
        private string $costCentre,
        private ?string $description,
        private ?string $statementPeriod,
        private ?string $customerId,
        private ?string $customerCode,
        private ?string $customerName,
        private ?string $customerState,
        private ?string $contactName,
        private ?string $contactEmail,
        private ?string $contactPhone,
        private ?ExpenseAttachmentRef $attachment,
        private ?int $bankFeedEntryId,
        private ?int $periodId,
        private LegacyExpenseIdentity $legacy,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $submittedAt,
        private DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt,
        private ?string $deletedBy,
        private ?RowVersionToken $rowVersion = null,
    ) {
    }

    public static function createDraft(
        ExpenseReceiptPublicId $publicId,
        UserId $ownerUserId,
        string $vendorName,
        ExpenseMoney $totalAmount,
        ExpenseMoney $gstAmount,
        ExpenseCurrency $currency,
        string $gstType,
        ?DateTimeImmutable $receiptDate,
        ExpenseCategory $category,
        string $costCentre,
        ?string $description,
        ?ExpenseAttachmentRef $attachment,
        DateTimeImmutable $now,
        ?UserId $managerUserId = null,
        LegacyExpenseIdentity $legacy = null,
        ?string $statementPeriod = null,
        ?string $customerId = null,
        ?string $customerCode = null,
        ?string $customerName = null,
        ?string $customerState = null,
        ?string $contactName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
    ): self {
        $vendor = trim($vendorName);
        if ($vendor === '') {
            throw InvalidExpenseValue::for('vendorName', $vendorName);
        }

        return new self(
            id: null,
            publicId: $publicId,
            ownerUserId: $ownerUserId,
            submittedByUserId: null,
            managerUserId: $managerUserId,
            status: ExpenseReceiptStatus::draft(),
            managerStatus: ManagerApprovalStatus::none(),
            managerReason: null,
            vendorName: $vendor,
            totalAmount: $totalAmount,
            gstAmount: $gstAmount,
            currency: $currency,
            gstType: trim($gstType) !== '' ? trim($gstType) : 'unknown',
            receiptDate: $receiptDate,
            category: $category,
            costCentre: trim($costCentre),
            description: self::nullableTrim($description),
            statementPeriod: self::nullableTrim($statementPeriod),
            customerId: self::nullableTrim($customerId),
            customerCode: self::nullableTrim($customerCode),
            customerName: self::nullableTrim($customerName),
            customerState: self::nullableTrim($customerState),
            contactName: self::nullableTrim($contactName),
            contactEmail: self::nullableTrim($contactEmail),
            contactPhone: self::nullableTrim($contactPhone),
            attachment: $attachment,
            bankFeedEntryId: null,
            periodId: null,
            legacy: $legacy ?? LegacyExpenseIdentity::empty(),
            createdAt: $now,
            submittedAt: null,
            updatedAt: $now,
            deletedAt: null,
            deletedBy: null,
            rowVersion: null,
        );
    }

    public static function reconstitute(
        ?ExpenseReceiptId $id,
        ExpenseReceiptPublicId $publicId,
        UserId $ownerUserId,
        ?UserId $submittedByUserId,
        ?UserId $managerUserId,
        ExpenseReceiptStatus $status,
        ManagerApprovalStatus $managerStatus,
        ?string $managerReason,
        string $vendorName,
        ExpenseMoney $totalAmount,
        ExpenseMoney $gstAmount,
        ExpenseCurrency $currency,
        string $gstType,
        ?DateTimeImmutable $receiptDate,
        ExpenseCategory $category,
        string $costCentre,
        ?string $description,
        ?string $statementPeriod,
        ?string $customerId,
        ?string $customerCode,
        ?string $customerName,
        ?string $customerState,
        ?string $contactName,
        ?string $contactEmail,
        ?string $contactPhone,
        ?ExpenseAttachmentRef $attachment,
        ?int $bankFeedEntryId,
        ?int $periodId,
        LegacyExpenseIdentity $legacy,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $submittedAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $deletedAt,
        ?string $deletedBy,
        ?RowVersionToken $rowVersion = null,
    ): self {
        return new self(
            $id,
            $publicId,
            $ownerUserId,
            $submittedByUserId,
            $managerUserId,
            $status,
            $managerStatus,
            $managerReason,
            $vendorName,
            $totalAmount,
            $gstAmount,
            $currency,
            $gstType,
            $receiptDate,
            $category,
            $costCentre,
            $description,
            $statementPeriod,
            $customerId,
            $customerCode,
            $customerName,
            $customerState,
            $contactName,
            $contactEmail,
            $contactPhone,
            $attachment,
            $bankFeedEntryId,
            $periodId,
            $legacy,
            $createdAt,
            $submittedAt,
            $updatedAt,
            $deletedAt,
            $deletedBy,
            $rowVersion,
        );
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function withId(ExpenseReceiptId $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withRowVersion(RowVersionToken $rowVersion): self
    {
        $clone = clone $this;
        $clone->rowVersion = $rowVersion;

        return $clone;
    }

    public function updateDraftDetails(
        string $vendorName,
        ExpenseMoney $totalAmount,
        ExpenseMoney $gstAmount,
        ExpenseCurrency $currency,
        string $gstType,
        ?DateTimeImmutable $receiptDate,
        ExpenseCategory $category,
        string $costCentre,
        ?string $description,
        DateTimeImmutable $now,
    ): void {
        if ($this->deletedAt !== null) {
            throw InvalidExpenseStatusTransition::notEditable($this->status);
        }
        if (!$this->status->isEditableByOwner()) {
            throw InvalidExpenseStatusTransition::notEditable($this->status);
        }

        $vendor = trim($vendorName);
        if ($vendor === '') {
            throw InvalidExpenseValue::for('vendorName', $vendorName);
        }

        $this->vendorName = $vendor;
        $this->totalAmount = $totalAmount;
        $this->gstAmount = $gstAmount;
        $this->currency = $currency;
        $this->gstType = trim($gstType) !== '' ? trim($gstType) : $this->gstType;
        $this->receiptDate = $receiptDate;
        $this->category = $category;
        $this->costCentre = trim($costCentre);
        $this->description = self::nullableTrim($description);
        $this->updatedAt = $now;
    }

    public function submit(UserId $submittedByUserId, ?UserId $managerUserId, DateTimeImmutable $now): void
    {
        if ($this->deletedAt !== null) {
            throw InvalidExpenseStatusTransition::fromTo($this->status, ExpenseReceiptStatus::pending());
        }
        if (!$this->status->isDraft() && !$this->status->isRejected()) {
            // Legacy: submitDraft only from draft; rejected can be fixed & resubmitted in UX —
            // align with draft→pending path for editable rejected receipts.
            if (!$this->status->isEditableByOwner()) {
                throw InvalidExpenseStatusTransition::fromTo($this->status, ExpenseReceiptStatus::pending());
            }
        }
        if ($managerUserId === null && $this->managerUserId === null) {
            // caller should throw ExpenseManagerRequired — keep domain free of that app exception
            throw InvalidExpenseValue::for('managerUserId', '');
        }

        $this->status = ExpenseReceiptStatus::pending();
        $this->managerStatus = ManagerApprovalStatus::pending();
        $this->submittedByUserId = $submittedByUserId;
        if ($managerUserId !== null) {
            $this->managerUserId = $managerUserId;
        }
        $this->submittedAt = $now;
        $this->updatedAt = $now;
    }

    public function approve(DateTimeImmutable $now): void
    {
        if ($this->deletedAt !== null || !$this->status->isPending()) {
            throw InvalidExpenseStatusTransition::fromTo($this->status, ExpenseReceiptStatus::approved());
        }
        $this->status = ExpenseReceiptStatus::approved();
        $this->managerStatus = ManagerApprovalStatus::approved();
        $this->updatedAt = $now;
    }

    public function reject(string $reason, DateTimeImmutable $now): void
    {
        if ($this->deletedAt !== null || !$this->status->isPending()) {
            throw InvalidExpenseStatusTransition::fromTo($this->status, ExpenseReceiptStatus::rejected());
        }
        $this->status = ExpenseReceiptStatus::rejected();
        $this->managerStatus = ManagerApprovalStatus::rejected();
        $this->managerReason = trim($reason);
        $this->updatedAt = $now;
    }

    /** Finance send-back: approved → pending (legacy sendBack). Note is audit/email only. */
    public function sendBack(DateTimeImmutable $now): void
    {
        if ($this->deletedAt !== null || !$this->status->isApproved()) {
            throw InvalidExpenseStatusTransition::fromTo($this->status, ExpenseReceiptStatus::pending());
        }
        $this->status = ExpenseReceiptStatus::pending();
        $this->managerStatus = ManagerApprovalStatus::pending();
        $this->updatedAt = $now;
    }

    public function softDelete(string $deletedBy, DateTimeImmutable $now): void
    {
        if ($this->deletedAt !== null) {
            return;
        }
        $this->deletedAt = $now;
        $this->deletedBy = trim($deletedBy);
        $this->bankFeedEntryId = null;
        $this->updatedAt = $now;
    }

    public function id(): ?ExpenseReceiptId { return $this->id; }
    public function publicId(): ExpenseReceiptPublicId { return $this->publicId; }
    public function ownerUserId(): UserId { return $this->ownerUserId; }
    public function submittedByUserId(): ?UserId { return $this->submittedByUserId; }
    public function managerUserId(): ?UserId { return $this->managerUserId; }
    public function status(): ExpenseReceiptStatus { return $this->status; }
    public function managerStatus(): ManagerApprovalStatus { return $this->managerStatus; }
    public function managerReason(): ?string { return $this->managerReason; }
    public function vendorName(): string { return $this->vendorName; }
    public function totalAmount(): ExpenseMoney { return $this->totalAmount; }
    public function gstAmount(): ExpenseMoney { return $this->gstAmount; }
    public function currency(): ExpenseCurrency { return $this->currency; }
    public function gstType(): string { return $this->gstType; }
    public function receiptDate(): ?DateTimeImmutable { return $this->receiptDate; }
    public function category(): ExpenseCategory { return $this->category; }
    public function costCentre(): string { return $this->costCentre; }
    public function description(): ?string { return $this->description; }
    public function statementPeriod(): ?string { return $this->statementPeriod; }
    public function customerId(): ?string { return $this->customerId; }
    public function customerCode(): ?string { return $this->customerCode; }
    public function customerName(): ?string { return $this->customerName; }
    public function customerState(): ?string { return $this->customerState; }
    public function contactName(): ?string { return $this->contactName; }
    public function contactEmail(): ?string { return $this->contactEmail; }
    public function contactPhone(): ?string { return $this->contactPhone; }
    public function attachment(): ?ExpenseAttachmentRef { return $this->attachment; }
    public function bankFeedEntryId(): ?int { return $this->bankFeedEntryId; }
    public function periodId(): ?int { return $this->periodId; }
    public function legacy(): LegacyExpenseIdentity { return $this->legacy; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function submittedAt(): ?DateTimeImmutable { return $this->submittedAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function deletedAt(): ?DateTimeImmutable { return $this->deletedAt; }
    public function deletedBy(): ?string { return $this->deletedBy; }
    public function rowVersion(): ?RowVersionToken { return $this->rowVersion; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
}