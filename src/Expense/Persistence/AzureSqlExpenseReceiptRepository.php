<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseFinanceResolution;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptStatus;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseIdentity;
use TriCab\Core\Expense\Domain\ValueObject\ManagerApprovalStatus;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class AzureSqlExpenseReceiptRepository implements ExpenseReceiptRepository
{
    use AzureSqlTransaction;

    private const SELECT_COLUMNS = <<<'SQL'
    r.[ExpenseReceiptId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [PublicId],
    r.[OwnerUserId],
    r.[SubmittedByUserId],
    r.[ManagerUserId],
    r.[Status],
    r.[ManagerStatus],
    r.[ManagerReason],
    r.[VendorName],
    CONVERT(VARCHAR(32), r.[TotalAmount]) AS [TotalAmount],
    CONVERT(VARCHAR(32), r.[GstAmount]) AS [GstAmount],
    r.[Currency],
    r.[GstType],
    CONVERT(VARCHAR(10), r.[ReceiptDate], 23) AS [ReceiptDate],
    r.[Category],
    r.[OtherCategory],
    r.[CostCentre],
    r.[Description],
    r.[StatementPeriod],
    r.[CustomerCode],
    r.[CustomerName],
    r.[CustomerState],
    r.[ContactName],
    r.[ContactEmail],
    r.[ContactPhone],
    r.[AttachmentSource],
    r.[AttachmentReference],
    r.[AttachmentFileName],
    r.[BankFeedEntryId],
    r.[PeriodId],
    r.[LegacyReceiptId],
    r.[LegacyCreatedBy],
    r.[LegacySubmittedBy],
    r.[LegacyManagerLogin],
    r.[LegacyFileUrl],
    CONVERT(VARCHAR(33), r.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), r.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
    CONVERT(VARCHAR(33), r.[UpdatedAtUtc], 127) AS [UpdatedAtUtc],
    CONVERT(VARCHAR(33), r.[DeletedAtUtc], 127) AS [DeletedAtUtc],
    r.[DeletedBy],
    r.[Resolution],
    r.[ResolutionNote],
    r.[ResolvedByUserId],
    r.[LegacyResolvedBy],
    CONVERT(VARCHAR(33), r.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARBINARY(8), r.[RowVersion]) AS [RowVersion]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function save(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): ExpenseReceipt
    {
        return $this->runInTransaction($this->connection, function () use ($receipt, $expectedRowVersion): ExpenseReceipt {
            if ($receipt->id() === null) {
                return $this->insert($receipt);
            }

            return $this->update($receipt, $expectedRowVersion);
        });
    }

    public function findByPublicId(ExpenseReceiptPublicId $publicId): ?ExpenseReceipt
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseReceipts] r
WHERE r.[PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findById(int $id): ?ExpenseReceipt
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseReceipts] r
WHERE r.[ExpenseReceiptId] = :id',
            [':id' => $id],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function listOwnedBy(UserId $ownerUserId, bool $includeDeleted = false): array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseReceipts] r
WHERE r.[OwnerUserId] = :ownerUserId';
        if (!$includeDeleted) {
            $sql .= ' AND r.[DeletedAtUtc] IS NULL';
        }
        $sql .= ' ORDER BY r.[ExpenseReceiptId] DESC';

        $rows = $this->connection->fetchAll($sql, [':ownerUserId' => $ownerUserId->toInt()]);

        return array_map(fn (array $row): ExpenseReceipt => $this->hydrate($row), $rows);
    }

    public function listPendingForManager(UserId $managerUserId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseReceipts] r
WHERE r.[ManagerUserId] = :managerUserId
  AND r.[Status] = N\'pending\'
  AND r.[DeletedAtUtc] IS NULL
ORDER BY r.[ExpenseReceiptId] DESC',
            [':managerUserId' => $managerUserId->toInt()],
        );

        return array_map(fn (array $row): ExpenseReceipt => $this->hydrate($row), $rows);
    }

    public function listMatchCandidates(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseReceipts] r
WHERE r.[BankFeedEntryId] IS NULL
  AND r.[Status] <> N\'draft\'
  AND r.[DeletedAtUtc] IS NULL
ORDER BY r.[ExpenseReceiptId] DESC',
        );

        return array_map(fn (array $row): ExpenseReceipt => $this->hydrate($row), $rows);
    }

    public function listInReceiptDateRange(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $includeDeleted = false,
    ): array {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseReceipts] r
WHERE r.[ReceiptDate] >= :startDate
  AND r.[ReceiptDate] <= :endDate';
        if (!$includeDeleted) {
            $sql .= ' AND r.[DeletedAtUtc] IS NULL';
        }
        $sql .= ' ORDER BY r.[ExpenseReceiptId] DESC';

        $rows = $this->connection->fetchAll($sql, [
            ':startDate' => $start->format('Y-m-d'),
            ':endDate' => $end->format('Y-m-d'),
        ]);

        return array_map(fn (array $row): ExpenseReceipt => $this->hydrate($row), $rows);
    }

    public function listAll(bool $includeDeleted = false): array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseReceipts] r';
        if (!$includeDeleted) {
            $sql .= ' WHERE r.[DeletedAtUtc] IS NULL';
        }
        $sql .= ' ORDER BY r.[ExpenseReceiptId] DESC';

        $rows = $this->connection->fetchAll($sql);

        return array_map(fn (array $row): ExpenseReceipt => $this->hydrate($row), $rows);
    }

    public function deletePermanently(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): void
    {
        $sql = 'DELETE FROM [expense].[ExpenseReceipts]
OUTPUT DELETED.[ExpenseReceiptId]
WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NOT NULL';
        $params = [':publicId' => $receipt->publicId()->toString()];
        if ($expectedRowVersion !== null) {
            $sql .= ' AND [RowVersion] = :expectedRowVersion';
            $params[':expectedRowVersion'] = ProductRowVersionMapper::toSqlBinary($expectedRowVersion);
        }

        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            throw ExpenseConcurrencyConflict::forReceipt();
        }
    }

    private function insert(ExpenseReceipt $receipt): ExpenseReceipt
    {
        $attachment = $receipt->attachment();
        $legacy = $receipt->legacy();

        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [expense].[ExpenseReceipts] (
    [PublicId],
    [OwnerUserId],
    [SubmittedByUserId],
    [ManagerUserId],
    [Status],
    [ManagerStatus],
    [ManagerReason],
    [VendorName],
    [TotalAmount],
    [GstAmount],
    [Currency],
    [GstType],
    [ReceiptDate],
    [Category],
    [OtherCategory],
    [CostCentre],
    [Description],
    [StatementPeriod],
    [CustomerCode],
    [CustomerName],
    [CustomerState],
    [ContactName],
    [ContactEmail],
    [ContactPhone],
    [AttachmentSource],
    [AttachmentReference],
    [AttachmentFileName],
    [BankFeedEntryId],
    [PeriodId],
    [LegacyReceiptId],
    [LegacyCreatedBy],
    [LegacySubmittedBy],
    [LegacyManagerLogin],
    [LegacyFileUrl],
    [CreatedAtUtc],
    [SubmittedAtUtc],
    [UpdatedAtUtc],
    [DeletedAtUtc],
    [DeletedBy],
    [Resolution],
    [ResolutionNote],
    [ResolvedByUserId],
    [LegacyResolvedBy],
    [ResolvedAtUtc]
)
OUTPUT
    INSERTED.[ExpenseReceiptId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[OwnerUserId],
    INSERTED.[SubmittedByUserId],
    INSERTED.[ManagerUserId],
    INSERTED.[Status],
    INSERTED.[ManagerStatus],
    INSERTED.[ManagerReason],
    INSERTED.[VendorName],
    CONVERT(VARCHAR(32), INSERTED.[TotalAmount]) AS [TotalAmount],
    CONVERT(VARCHAR(32), INSERTED.[GstAmount]) AS [GstAmount],
    INSERTED.[Currency],
    INSERTED.[GstType],
    CONVERT(VARCHAR(10), INSERTED.[ReceiptDate], 23) AS [ReceiptDate],
    INSERTED.[Category],
    INSERTED.[OtherCategory],
    INSERTED.[CostCentre],
    INSERTED.[Description],
    INSERTED.[StatementPeriod],
    INSERTED.[CustomerCode],
    INSERTED.[CustomerName],
    INSERTED.[CustomerState],
    INSERTED.[ContactName],
    INSERTED.[ContactEmail],
    INSERTED.[ContactPhone],
    INSERTED.[AttachmentSource],
    INSERTED.[AttachmentReference],
    INSERTED.[AttachmentFileName],
    INSERTED.[BankFeedEntryId],
    INSERTED.[PeriodId],
    INSERTED.[LegacyReceiptId],
    INSERTED.[LegacyCreatedBy],
    INSERTED.[LegacySubmittedBy],
    INSERTED.[LegacyManagerLogin],
    INSERTED.[LegacyFileUrl],
    CONVERT(VARCHAR(33), INSERTED.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), INSERTED.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
    CONVERT(VARCHAR(33), INSERTED.[UpdatedAtUtc], 127) AS [UpdatedAtUtc],
    CONVERT(VARCHAR(33), INSERTED.[DeletedAtUtc], 127) AS [DeletedAtUtc],
    INSERTED.[DeletedBy],
    INSERTED.[Resolution],
    INSERTED.[ResolutionNote],
    INSERTED.[ResolvedByUserId],
    INSERTED.[LegacyResolvedBy],
    CONVERT(VARCHAR(33), INSERTED.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARBINARY(8), INSERTED.[RowVersion]) AS [RowVersion]
VALUES (
    :publicId,
    :ownerUserId,
    :submittedByUserId,
    :managerUserId,
    :status,
    :managerStatus,
    :managerReason,
    :vendorName,
    :totalAmount,
    :gstAmount,
    :currency,
    :gstType,
    :receiptDate,
    :category,
    :otherCategory,
    :costCentre,
    :description,
    :statementPeriod,
    :customerCode,
    :customerName,
    :customerState,
    :contactName,
    :contactEmail,
    :contactPhone,
    :attachmentSource,
    :attachmentReference,
    :attachmentFileName,
    :bankFeedEntryId,
    :periodId,
    :legacyReceiptId,
    :legacyCreatedBy,
    :legacySubmittedBy,
    :legacyManagerLogin,
    :legacyFileUrl,
    :createdAtUtc,
    :submittedAtUtc,
    :updatedAtUtc,
    :deletedAtUtc,
    :deletedBy,
    :resolution,
    :resolutionNote,
    :resolvedByUserId,
    :legacyResolvedBy,
    :resolvedAtUtc
)
SQL,
            $this->writeParams($receipt, $attachment, $legacy),
        );

        return $this->hydrate($rows[0]);
    }

    private function update(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion): ExpenseReceipt
    {
        $attachment = $receipt->attachment();
        $legacy = $receipt->legacy();
        $params = $this->writeParams($receipt, $attachment, $legacy);
        $params[':expenseReceiptId'] = $receipt->id()?->toInt();

        $sql = <<<'SQL'
UPDATE [expense].[ExpenseReceipts]
SET
    [OwnerUserId] = :ownerUserId,
    [SubmittedByUserId] = :submittedByUserId,
    [ManagerUserId] = :managerUserId,
    [Status] = :status,
    [ManagerStatus] = :managerStatus,
    [ManagerReason] = :managerReason,
    [VendorName] = :vendorName,
    [TotalAmount] = :totalAmount,
    [GstAmount] = :gstAmount,
    [Currency] = :currency,
    [GstType] = :gstType,
    [ReceiptDate] = :receiptDate,
    [Category] = :category,
    [OtherCategory] = :otherCategory,
    [CostCentre] = :costCentre,
    [Description] = :description,
    [StatementPeriod] = :statementPeriod,
    [CustomerCode] = :customerCode,
    [CustomerName] = :customerName,
    [CustomerState] = :customerState,
    [ContactName] = :contactName,
    [ContactEmail] = :contactEmail,
    [ContactPhone] = :contactPhone,
    [AttachmentSource] = :attachmentSource,
    [AttachmentReference] = :attachmentReference,
    [AttachmentFileName] = :attachmentFileName,
    [BankFeedEntryId] = :bankFeedEntryId,
    [PeriodId] = :periodId,
    [LegacyReceiptId] = :legacyReceiptId,
    [LegacyCreatedBy] = :legacyCreatedBy,
    [LegacySubmittedBy] = :legacySubmittedBy,
    [LegacyManagerLogin] = :legacyManagerLogin,
    [LegacyFileUrl] = :legacyFileUrl,
    [SubmittedAtUtc] = :submittedAtUtc,
    [UpdatedAtUtc] = :updatedAtUtc,
    [DeletedAtUtc] = :deletedAtUtc,
    [DeletedBy] = :deletedBy,
    [Resolution] = :resolution,
    [ResolutionNote] = :resolutionNote,
    [ResolvedByUserId] = :resolvedByUserId,
    [LegacyResolvedBy] = :legacyResolvedBy,
    [ResolvedAtUtc] = :resolvedAtUtc
OUTPUT
    INSERTED.[ExpenseReceiptId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[OwnerUserId],
    INSERTED.[SubmittedByUserId],
    INSERTED.[ManagerUserId],
    INSERTED.[Status],
    INSERTED.[ManagerStatus],
    INSERTED.[ManagerReason],
    INSERTED.[VendorName],
    CONVERT(VARCHAR(32), INSERTED.[TotalAmount]) AS [TotalAmount],
    CONVERT(VARCHAR(32), INSERTED.[GstAmount]) AS [GstAmount],
    INSERTED.[Currency],
    INSERTED.[GstType],
    CONVERT(VARCHAR(10), INSERTED.[ReceiptDate], 23) AS [ReceiptDate],
    INSERTED.[Category],
    INSERTED.[OtherCategory],
    INSERTED.[CostCentre],
    INSERTED.[Description],
    INSERTED.[StatementPeriod],
    INSERTED.[CustomerCode],
    INSERTED.[CustomerName],
    INSERTED.[CustomerState],
    INSERTED.[ContactName],
    INSERTED.[ContactEmail],
    INSERTED.[ContactPhone],
    INSERTED.[AttachmentSource],
    INSERTED.[AttachmentReference],
    INSERTED.[AttachmentFileName],
    INSERTED.[BankFeedEntryId],
    INSERTED.[PeriodId],
    INSERTED.[LegacyReceiptId],
    INSERTED.[LegacyCreatedBy],
    INSERTED.[LegacySubmittedBy],
    INSERTED.[LegacyManagerLogin],
    INSERTED.[LegacyFileUrl],
    CONVERT(VARCHAR(33), INSERTED.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), INSERTED.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
    CONVERT(VARCHAR(33), INSERTED.[UpdatedAtUtc], 127) AS [UpdatedAtUtc],
    CONVERT(VARCHAR(33), INSERTED.[DeletedAtUtc], 127) AS [DeletedAtUtc],
    INSERTED.[DeletedBy],
    INSERTED.[Resolution],
    INSERTED.[ResolutionNote],
    INSERTED.[ResolvedByUserId],
    INSERTED.[LegacyResolvedBy],
    CONVERT(VARCHAR(33), INSERTED.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARBINARY(8), INSERTED.[RowVersion]) AS [RowVersion]
WHERE [ExpenseReceiptId] = :expenseReceiptId
SQL;

        // PublicId is immutable after insert; drop unused insert-only param.
        unset($params[':publicId'], $params[':createdAtUtc']);

        if ($expectedRowVersion !== null) {
            $sql .= ' AND [RowVersion] = :expectedRowVersion';
            $params[':expectedRowVersion'] = ProductRowVersionMapper::toSqlBinary($expectedRowVersion);
        }

        $rows = $this->connection->fetchAll($sql, $params);

        if ($rows === []) {
            throw ExpenseConcurrencyConflict::forReceipt();
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(
        ExpenseReceipt $receipt,
        ?ExpenseAttachmentRef $attachment,
        LegacyExpenseIdentity $legacy,
    ): array {
        return [
            ':publicId' => $receipt->publicId()->toString(),
            ':ownerUserId' => $receipt->ownerUserId()->toInt(),
            ':submittedByUserId' => $receipt->submittedByUserId()?->toInt(),
            ':managerUserId' => $receipt->managerUserId()?->toInt(),
            ':status' => $receipt->status()->toString(),
            ':managerStatus' => $receipt->managerStatus()->toString(),
            ':managerReason' => $receipt->managerReason(),
            ':vendorName' => $receipt->vendorName(),
            ':totalAmount' => $receipt->totalAmount()->amount(),
            ':gstAmount' => $receipt->gstAmount()->amount(),
            ':currency' => $receipt->currency()->toString(),
            ':gstType' => $receipt->gstType(),
            ':receiptDate' => $receipt->receiptDate()?->format('Y-m-d'),
            ':category' => $receipt->category()->rawValue(),
            ':otherCategory' => $receipt->category()->otherCategory(),
            ':costCentre' => $receipt->costCentre(),
            ':description' => $receipt->description(),
            ':statementPeriod' => $receipt->statementPeriod(),
            ':customerCode' => $receipt->customerCode(),
            ':customerName' => $receipt->customerName(),
            ':customerState' => $receipt->customerState(),
            ':contactName' => $receipt->contactName(),
            ':contactEmail' => $receipt->contactEmail(),
            ':contactPhone' => $receipt->contactPhone(),
            ':attachmentSource' => $attachment?->storageSource(),
            ':attachmentReference' => $attachment?->reference(),
            ':attachmentFileName' => $attachment?->fileName(),
            ':bankFeedEntryId' => $receipt->bankFeedEntryId(),
            ':periodId' => $receipt->periodId(),
            ':legacyReceiptId' => $legacy->legacyReceiptId(),
            ':legacyCreatedBy' => $legacy->createdBy(),
            ':legacySubmittedBy' => $legacy->submittedBy(),
            ':legacyManagerLogin' => $legacy->managerLogin(),
            ':legacyFileUrl' => $legacy->legacyFileUrl(),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($receipt->createdAt()),
            ':submittedAtUtc' => $receipt->submittedAt() !== null
                ? AzureSqlValueMapper::formatDateTime($receipt->submittedAt())
                : null,
            ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($receipt->updatedAt()),
            ':deletedAtUtc' => $receipt->deletedAt() !== null
                ? AzureSqlValueMapper::formatDateTime($receipt->deletedAt())
                : null,
            ':deletedBy' => $receipt->deletedBy(),
            ':resolution' => $receipt->resolution()?->toString(),
            ':resolutionNote' => $receipt->resolutionNote(),
            ':resolvedByUserId' => $receipt->resolvedByUserId()?->toInt(),
            ':legacyResolvedBy' => $receipt->legacyResolvedBy(),
            ':resolvedAtUtc' => $receipt->resolvedAtUtc() !== null
                ? AzureSqlValueMapper::formatDateTime($receipt->resolvedAtUtc())
                : null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ExpenseReceipt
    {
        $submittedBy = AzureSqlValueMapper::nullableInt($row['SubmittedByUserId'] ?? null);
        $managerUserId = AzureSqlValueMapper::nullableInt($row['ManagerUserId'] ?? null);
        $resolvedByUserId = AzureSqlValueMapper::nullableInt($row['ResolvedByUserId'] ?? null);
        $resolutionRaw = AzureSqlValueMapper::nullableString($row['Resolution'] ?? null);
        $receiptDateRaw = AzureSqlValueMapper::nullableString($row['ReceiptDate'] ?? null);
        $receiptDate = null;
        if ($receiptDateRaw !== null) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $receiptDateRaw)
                ?: new DateTimeImmutable($receiptDateRaw);
            $receiptDate = $parsed;
        }

        return ExpenseReceipt::reconstitute(
            id: ExpenseReceiptId::fromInt(AzureSqlValueMapper::intValue($row['ExpenseReceiptId'] ?? 0)),
            publicId: ExpenseReceiptPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            ownerUserId: UserId::fromInt(AzureSqlValueMapper::intValue($row['OwnerUserId'] ?? 0)),
            submittedByUserId: $submittedBy !== null ? UserId::fromInt($submittedBy) : null,
            managerUserId: $managerUserId !== null ? UserId::fromInt($managerUserId) : null,
            status: ExpenseReceiptStatus::fromString(AzureSqlValueMapper::stringValue($row['Status'] ?? '')),
            managerStatus: ManagerApprovalStatus::fromString(AzureSqlValueMapper::nullableString($row['ManagerStatus'] ?? null)),
            managerReason: AzureSqlValueMapper::nullableString($row['ManagerReason'] ?? null),
            vendorName: AzureSqlValueMapper::stringValue($row['VendorName'] ?? ''),
            totalAmount: ExpenseMoney::fromString(AzureSqlValueMapper::stringValue($row['TotalAmount'] ?? '0')),
            gstAmount: ExpenseMoney::fromString(AzureSqlValueMapper::stringValue($row['GstAmount'] ?? '0')),
            currency: ExpenseCurrency::fromString(AzureSqlValueMapper::stringValue($row['Currency'] ?? 'AUD')),
            gstType: AzureSqlValueMapper::stringValue($row['GstType'] ?? 'unknown'),
            receiptDate: $receiptDate,
            category: ExpenseCategory::fromLegacy(
                AzureSqlValueMapper::nullableString($row['Category'] ?? null),
                AzureSqlValueMapper::nullableString($row['OtherCategory'] ?? null),
            ),
            costCentre: AzureSqlValueMapper::stringValue($row['CostCentre'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            statementPeriod: AzureSqlValueMapper::nullableString($row['StatementPeriod'] ?? null),
            customerId: null,
            customerCode: AzureSqlValueMapper::nullableString($row['CustomerCode'] ?? null),
            customerName: AzureSqlValueMapper::nullableString($row['CustomerName'] ?? null),
            customerState: AzureSqlValueMapper::nullableString($row['CustomerState'] ?? null),
            contactName: AzureSqlValueMapper::nullableString($row['ContactName'] ?? null),
            contactEmail: AzureSqlValueMapper::nullableString($row['ContactEmail'] ?? null),
            contactPhone: AzureSqlValueMapper::nullableString($row['ContactPhone'] ?? null),
            attachment: $this->mapAttachment($row),
            bankFeedEntryId: AzureSqlValueMapper::nullableInt($row['BankFeedEntryId'] ?? null),
            periodId: AzureSqlValueMapper::nullableInt($row['PeriodId'] ?? null),
            legacy: LegacyExpenseIdentity::of(
                createdBy: AzureSqlValueMapper::nullableString($row['LegacyCreatedBy'] ?? null),
                submittedBy: AzureSqlValueMapper::nullableString($row['LegacySubmittedBy'] ?? null),
                managerLogin: AzureSqlValueMapper::nullableString($row['LegacyManagerLogin'] ?? null),
                legacyReceiptId: AzureSqlValueMapper::nullableInt($row['LegacyReceiptId'] ?? null),
                legacyFileUrl: AzureSqlValueMapper::nullableString($row['LegacyFileUrl'] ?? null),
            ),
            createdAt: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            submittedAt: AzureSqlValueMapper::nullableDateTime($row['SubmittedAtUtc'] ?? null),
            updatedAt: AzureSqlValueMapper::requireDateTime($row['UpdatedAtUtc'] ?? null, 'UpdatedAtUtc'),
            deletedAt: AzureSqlValueMapper::nullableDateTime($row['DeletedAtUtc'] ?? null),
            deletedBy: AzureSqlValueMapper::nullableString($row['DeletedBy'] ?? null),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
            resolution: $resolutionRaw !== null ? ExpenseFinanceResolution::fromString($resolutionRaw) : null,
            resolutionNote: AzureSqlValueMapper::nullableString($row['ResolutionNote'] ?? null),
            resolvedByUserId: $resolvedByUserId !== null ? UserId::fromInt($resolvedByUserId) : null,
            legacyResolvedBy: AzureSqlValueMapper::nullableString($row['LegacyResolvedBy'] ?? null),
            resolvedAtUtc: AzureSqlValueMapper::nullableDateTime($row['ResolvedAtUtc'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapAttachment(array $row): ?ExpenseAttachmentRef
    {
        $reference = AzureSqlValueMapper::nullableString($row['AttachmentReference'] ?? null);
        if ($reference === null) {
            return null;
        }

        $source = AzureSqlValueMapper::nullableString($row['AttachmentSource'] ?? null)
            ?? ExpenseAttachmentRef::SOURCE_UNKNOWN;

        return ExpenseAttachmentRef::reconstitute(
            $source,
            $reference,
            AzureSqlValueMapper::nullableString($row['AttachmentFileName'] ?? null),
        );
    }
}
