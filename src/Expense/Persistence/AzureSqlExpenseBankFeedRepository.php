<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\ValueObject\BankFeedMatchStatus;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseFinanceResolution;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class AzureSqlExpenseBankFeedRepository implements ExpenseBankFeedRepository
{
    use AzureSqlTransaction;

    private const SELECT_COLUMNS = <<<'SQL'
    b.[ExpenseBankFeedEntryId],
    CONVERT(NVARCHAR(36), b.[PublicId]) AS [PublicId],
    b.[BankReference],
    b.[CardholderEmail],
    b.[CardholderUserId],
    b.[FirstName],
    b.[LastName],
    b.[Country],
    CONVERT(VARCHAR(10), b.[TransactionDate], 23) AS [TransactionDate],
    b.[MerchantName],
    CONVERT(VARCHAR(32), b.[Amount]) AS [Amount],
    b.[Currency],
    b.[MatchStatus],
    b.[ExpenseReceiptId],
    b.[ExpensePeriodId],
    b.[Resolution],
    b.[OffsetBankFeedEntryId],
    b.[ResolutionNote],
    b.[ResolvedByUserId],
    b.[LegacyResolvedBy],
    CONVERT(VARCHAR(33), b.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARCHAR(10), b.[PostingDate], 23) AS [PostingDate],
    CONVERT(VARCHAR(10), b.[StatementDate], 23) AS [StatementDate],
    b.[MerchantCity],
    b.[MerchantCountry],
    b.[MerchantPostcode],
    CONVERT(VARCHAR(32), b.[ForeignAmount]) AS [ForeignAmount],
    b.[ForeignCurrency],
    CONVERT(VARCHAR(32), b.[NetAmount]) AS [NetAmount],
    CONVERT(VARCHAR(32), b.[TaxAmount]) AS [TaxAmount],
    b.[TransactionType],
    b.[MerchantCategoryGroup],
    b.[LegacyUploadedBy],
    b.[LegacyBankFeedId],
    CONVERT(VARCHAR(33), b.[UploadedAtUtc], 127) AS [UploadedAtUtc],
    CONVERT(VARBINARY(8), b.[RowVersion]) AS [RowVersion]
SQL;

    private const OUTPUT_COLUMNS = <<<'SQL'
    INSERTED.[ExpenseBankFeedEntryId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[BankReference],
    INSERTED.[CardholderEmail],
    INSERTED.[CardholderUserId],
    INSERTED.[FirstName],
    INSERTED.[LastName],
    INSERTED.[Country],
    CONVERT(VARCHAR(10), INSERTED.[TransactionDate], 23) AS [TransactionDate],
    INSERTED.[MerchantName],
    CONVERT(VARCHAR(32), INSERTED.[Amount]) AS [Amount],
    INSERTED.[Currency],
    INSERTED.[MatchStatus],
    INSERTED.[ExpenseReceiptId],
    INSERTED.[ExpensePeriodId],
    INSERTED.[Resolution],
    INSERTED.[OffsetBankFeedEntryId],
    INSERTED.[ResolutionNote],
    INSERTED.[ResolvedByUserId],
    INSERTED.[LegacyResolvedBy],
    CONVERT(VARCHAR(33), INSERTED.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARCHAR(10), INSERTED.[PostingDate], 23) AS [PostingDate],
    CONVERT(VARCHAR(10), INSERTED.[StatementDate], 23) AS [StatementDate],
    INSERTED.[MerchantCity],
    INSERTED.[MerchantCountry],
    INSERTED.[MerchantPostcode],
    CONVERT(VARCHAR(32), INSERTED.[ForeignAmount]) AS [ForeignAmount],
    INSERTED.[ForeignCurrency],
    CONVERT(VARCHAR(32), INSERTED.[NetAmount]) AS [NetAmount],
    CONVERT(VARCHAR(32), INSERTED.[TaxAmount]) AS [TaxAmount],
    INSERTED.[TransactionType],
    INSERTED.[MerchantCategoryGroup],
    INSERTED.[LegacyUploadedBy],
    INSERTED.[LegacyBankFeedId],
    CONVERT(VARCHAR(33), INSERTED.[UploadedAtUtc], 127) AS [UploadedAtUtc],
    CONVERT(VARBINARY(8), INSERTED.[RowVersion]) AS [RowVersion]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function save(ExpenseBankFeedEntry $entry, ?RowVersionToken $expectedRowVersion = null): ExpenseBankFeedEntry
    {
        return $this->runInTransaction($this->connection, function () use ($entry, $expectedRowVersion): ExpenseBankFeedEntry {
            if ($entry->id() === null) {
                return $this->insert($entry);
            }

            return $this->update($entry, $expectedRowVersion);
        });
    }

    public function findByPublicId(ExpenseBankFeedPublicId $publicId): ?ExpenseBankFeedEntry
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findById(int $id): ?ExpenseBankFeedEntry
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[ExpenseBankFeedEntryId] = :id',
            [':id' => $id],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findByBankReference(string $bankReference): ?ExpenseBankFeedEntry
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[BankReference] = :bankReference',
            [':bankReference' => trim($bankReference)],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function listUnmatchedByCountry(string $country): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[Country] = :country
  AND b.[MatchStatus] = N\'unmatched\'
ORDER BY b.[ExpenseBankFeedEntryId] DESC',
            [':country' => strtoupper(trim($country))],
        );

        return array_map(fn (array $row): ExpenseBankFeedEntry => $this->hydrate($row), $rows);
    }

    public function listByPeriodId(int $periodId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[ExpensePeriodId] = :periodId
ORDER BY b.[ExpenseBankFeedEntryId] DESC',
            [':periodId' => $periodId],
        );

        return array_map(fn (array $row): ExpenseBankFeedEntry => $this->hydrate($row), $rows);
    }

    public function listByCountry(string $country): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[Country] = :country
ORDER BY b.[ExpenseBankFeedEntryId] DESC',
            [':country' => strtoupper(trim($country))],
        );

        return array_map(fn (array $row): ExpenseBankFeedEntry => $this->hydrate($row), $rows);
    }

    public function listEligibleForOffset(string $country): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[Country] = :country
  AND b.[MatchStatus] = N\'unmatched\'
  AND b.[ExpenseReceiptId] IS NULL
  AND b.[Resolution] IS NULL
  AND (b.[TransactionType] IS NULL OR LOWER(b.[TransactionType]) <> LOWER(:paymentType))
ORDER BY b.[ExpenseBankFeedEntryId] DESC',
            [
                ':country' => strtoupper(trim($country)),
                ':paymentType' => Module::TYPE_PAYMENT,
            ],
        );

        return array_map(fn (array $row): ExpenseBankFeedEntry => $this->hydrate($row), $rows);
    }

    public function listAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseBankFeedEntries] b
ORDER BY b.[ExpenseBankFeedEntryId] DESC',
        );

        return array_map(fn (array $row): ExpenseBankFeedEntry => $this->hydrate($row), $rows);
    }

    public function minTransactionDate(string $country): ?DateTimeImmutable
    {
        $rows = $this->connection->fetchAll(
            'SELECT CONVERT(VARCHAR(10), MIN(b.[TransactionDate]), 23) AS [MinDate]
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[Country] = :country
  AND b.[TransactionDate] IS NOT NULL',
            [':country' => strtoupper(trim($country))],
        );

        return $this->nullableDate($rows[0]['MinDate'] ?? null);
    }

    public function maxStatementDate(string $country): ?DateTimeImmutable
    {
        $rows = $this->connection->fetchAll(
            'SELECT CONVERT(VARCHAR(10), MAX(b.[StatementDate]), 23) AS [MaxDate]
FROM [expense].[ExpenseBankFeedEntries] b
WHERE b.[Country] = :country
  AND b.[StatementDate] IS NOT NULL',
            [':country' => strtoupper(trim($country))],
        );

        return $this->nullableDate($rows[0]['MaxDate'] ?? null);
    }

    private function insert(ExpenseBankFeedEntry $entry): ExpenseBankFeedEntry
    {
        $rows = $this->connection->fetchAll(
            <<<SQL
INSERT INTO [expense].[ExpenseBankFeedEntries] (
    [PublicId],
    [BankReference],
    [CardholderEmail],
    [CardholderUserId],
    [FirstName],
    [LastName],
    [Country],
    [TransactionDate],
    [MerchantName],
    [Amount],
    [Currency],
    [MatchStatus],
    [ExpenseReceiptId],
    [ExpensePeriodId],
    [Resolution],
    [OffsetBankFeedEntryId],
    [ResolutionNote],
    [ResolvedByUserId],
    [LegacyResolvedBy],
    [ResolvedAtUtc],
    [PostingDate],
    [StatementDate],
    [MerchantCity],
    [MerchantCountry],
    [MerchantPostcode],
    [ForeignAmount],
    [ForeignCurrency],
    [NetAmount],
    [TaxAmount],
    [TransactionType],
    [MerchantCategoryGroup],
    [LegacyUploadedBy],
    [LegacyBankFeedId],
    [UploadedAtUtc]
)
OUTPUT
SQL . self::OUTPUT_COLUMNS . <<<'SQL'

VALUES (
    :publicId,
    :bankReference,
    :cardholderEmail,
    :cardholderUserId,
    :firstName,
    :lastName,
    :country,
    :transactionDate,
    :merchantName,
    :amount,
    :currency,
    :matchStatus,
    :expenseReceiptId,
    :expensePeriodId,
    :resolution,
    :offsetBankFeedEntryId,
    :resolutionNote,
    :resolvedByUserId,
    :legacyResolvedBy,
    :resolvedAtUtc,
    :postingDate,
    :statementDate,
    :merchantCity,
    :merchantCountry,
    :merchantPostcode,
    :foreignAmount,
    :foreignCurrency,
    :netAmount,
    :taxAmount,
    :transactionType,
    :merchantCategoryGroup,
    :legacyUploadedBy,
    :legacyBankFeedId,
    :uploadedAtUtc
)
SQL,
            $this->writeParams($entry),
        );

        return $this->hydrate($rows[0]);
    }

    private function update(ExpenseBankFeedEntry $entry, ?RowVersionToken $expectedRowVersion): ExpenseBankFeedEntry
    {
        $params = $this->writeParams($entry);
        $params[':expenseBankFeedEntryId'] = $entry->id();
        unset($params[':publicId'], $params[':uploadedAtUtc']);

        $sql = <<<SQL
UPDATE [expense].[ExpenseBankFeedEntries]
SET
    [BankReference] = :bankReference,
    [CardholderEmail] = :cardholderEmail,
    [CardholderUserId] = :cardholderUserId,
    [FirstName] = :firstName,
    [LastName] = :lastName,
    [Country] = :country,
    [TransactionDate] = :transactionDate,
    [MerchantName] = :merchantName,
    [Amount] = :amount,
    [Currency] = :currency,
    [MatchStatus] = :matchStatus,
    [ExpenseReceiptId] = :expenseReceiptId,
    [ExpensePeriodId] = :expensePeriodId,
    [Resolution] = :resolution,
    [OffsetBankFeedEntryId] = :offsetBankFeedEntryId,
    [ResolutionNote] = :resolutionNote,
    [ResolvedByUserId] = :resolvedByUserId,
    [LegacyResolvedBy] = :legacyResolvedBy,
    [ResolvedAtUtc] = :resolvedAtUtc,
    [PostingDate] = :postingDate,
    [StatementDate] = :statementDate,
    [MerchantCity] = :merchantCity,
    [MerchantCountry] = :merchantCountry,
    [MerchantPostcode] = :merchantPostcode,
    [ForeignAmount] = :foreignAmount,
    [ForeignCurrency] = :foreignCurrency,
    [NetAmount] = :netAmount,
    [TaxAmount] = :taxAmount,
    [TransactionType] = :transactionType,
    [MerchantCategoryGroup] = :merchantCategoryGroup,
    [LegacyUploadedBy] = :legacyUploadedBy,
    [LegacyBankFeedId] = :legacyBankFeedId
OUTPUT
SQL . self::OUTPUT_COLUMNS . <<<'SQL'

WHERE [ExpenseBankFeedEntryId] = :expenseBankFeedEntryId
SQL;

        if ($expectedRowVersion !== null) {
            $sql .= ' AND [RowVersion] = :expectedRowVersion';
            $params[':expectedRowVersion'] = ProductRowVersionMapper::toSqlBinary($expectedRowVersion);
        }

        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            throw ExpenseConcurrencyConflict::forBankFeed();
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(ExpenseBankFeedEntry $entry): array
    {
        return [
            ':publicId' => $entry->publicId()->toString(),
            ':bankReference' => $entry->bankReference(),
            ':cardholderEmail' => $entry->cardholderEmail(),
            ':cardholderUserId' => $entry->cardholderUserId()?->toInt(),
            ':firstName' => $entry->firstName(),
            ':lastName' => $entry->lastName(),
            ':country' => $entry->country(),
            ':transactionDate' => $entry->transactionDate()?->format('Y-m-d'),
            ':merchantName' => $entry->merchantName(),
            ':amount' => $entry->amount()->amount(),
            ':currency' => $entry->currency()->toString(),
            ':matchStatus' => $entry->matchStatus()->toString(),
            ':expenseReceiptId' => $entry->receiptId(),
            ':expensePeriodId' => $entry->periodId(),
            ':resolution' => $entry->resolution()?->toString(),
            ':offsetBankFeedEntryId' => $entry->offsetBankFeedEntryId(),
            ':resolutionNote' => $entry->resolutionNote(),
            ':resolvedByUserId' => $entry->resolvedByUserId()?->toInt(),
            ':legacyResolvedBy' => $entry->legacyResolvedBy(),
            ':resolvedAtUtc' => $entry->resolvedAtUtc() !== null
                ? AzureSqlValueMapper::formatDateTime($entry->resolvedAtUtc())
                : null,
            ':postingDate' => $entry->postingDate()?->format('Y-m-d'),
            ':statementDate' => $entry->statementDate()?->format('Y-m-d'),
            ':merchantCity' => $entry->merchantCity(),
            ':merchantCountry' => $entry->merchantCountry(),
            ':merchantPostcode' => $entry->merchantPostcode(),
            ':foreignAmount' => $entry->foreignAmount()?->amount(),
            ':foreignCurrency' => $entry->foreignCurrency()?->toString(),
            ':netAmount' => $entry->netAmount()?->amount(),
            ':taxAmount' => $entry->taxAmount()?->amount(),
            ':transactionType' => $entry->transactionType(),
            ':merchantCategoryGroup' => $entry->merchantCategoryGroup(),
            ':legacyUploadedBy' => $entry->legacyUploadedBy(),
            ':legacyBankFeedId' => $entry->legacyBankFeedId(),
            ':uploadedAtUtc' => AzureSqlValueMapper::formatDateTime($entry->uploadedAtUtc()),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ExpenseBankFeedEntry
    {
        $cardholderUserId = AzureSqlValueMapper::nullableInt($row['CardholderUserId'] ?? null);
        $resolvedByUserId = AzureSqlValueMapper::nullableInt($row['ResolvedByUserId'] ?? null);
        $resolutionRaw = AzureSqlValueMapper::nullableString($row['Resolution'] ?? null);
        $foreignCurrencyRaw = AzureSqlValueMapper::nullableString($row['ForeignCurrency'] ?? null);
        $foreignAmountRaw = AzureSqlValueMapper::nullableString($row['ForeignAmount'] ?? null);
        $netAmountRaw = AzureSqlValueMapper::nullableString($row['NetAmount'] ?? null);
        $taxAmountRaw = AzureSqlValueMapper::nullableString($row['TaxAmount'] ?? null);

        return ExpenseBankFeedEntry::reconstitute(
            id: AzureSqlValueMapper::intValue($row['ExpenseBankFeedEntryId'] ?? 0),
            publicId: ExpenseBankFeedPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            bankReference: AzureSqlValueMapper::stringValue($row['BankReference'] ?? ''),
            cardholderEmail: AzureSqlValueMapper::stringValue($row['CardholderEmail'] ?? ''),
            cardholderUserId: $cardholderUserId !== null ? UserId::fromInt($cardholderUserId) : null,
            firstName: AzureSqlValueMapper::nullableString($row['FirstName'] ?? null),
            lastName: AzureSqlValueMapper::nullableString($row['LastName'] ?? null),
            country: AzureSqlValueMapper::stringValue($row['Country'] ?? ''),
            transactionDate: $this->nullableDate($row['TransactionDate'] ?? null),
            postingDate: $this->nullableDate($row['PostingDate'] ?? null),
            statementDate: $this->nullableDate($row['StatementDate'] ?? null),
            merchantName: AzureSqlValueMapper::stringValue($row['MerchantName'] ?? ''),
            merchantCity: AzureSqlValueMapper::nullableString($row['MerchantCity'] ?? null),
            merchantCountry: AzureSqlValueMapper::nullableString($row['MerchantCountry'] ?? null),
            merchantPostcode: AzureSqlValueMapper::nullableString($row['MerchantPostcode'] ?? null),
            amount: ExpenseMoney::fromSignedString(AzureSqlValueMapper::stringValue($row['Amount'] ?? '0')),
            currency: ExpenseCurrency::fromString(AzureSqlValueMapper::stringValue($row['Currency'] ?? 'AUD')),
            foreignAmount: $foreignAmountRaw !== null ? ExpenseMoney::fromSignedString($foreignAmountRaw) : null,
            foreignCurrency: $foreignCurrencyRaw !== null ? ExpenseCurrency::fromString($foreignCurrencyRaw) : null,
            netAmount: $netAmountRaw !== null ? ExpenseMoney::fromSignedString($netAmountRaw) : null,
            taxAmount: $taxAmountRaw !== null ? ExpenseMoney::fromSignedString($taxAmountRaw) : null,
            transactionType: AzureSqlValueMapper::nullableString($row['TransactionType'] ?? null),
            merchantCategoryGroup: AzureSqlValueMapper::nullableString($row['MerchantCategoryGroup'] ?? null),
            matchStatus: BankFeedMatchStatus::fromString(AzureSqlValueMapper::stringValue($row['MatchStatus'] ?? '')),
            receiptId: AzureSqlValueMapper::nullableInt($row['ExpenseReceiptId'] ?? null),
            periodId: AzureSqlValueMapper::nullableInt($row['ExpensePeriodId'] ?? null),
            resolution: $resolutionRaw !== null ? ExpenseFinanceResolution::fromString($resolutionRaw) : null,
            offsetBankFeedEntryId: AzureSqlValueMapper::nullableInt($row['OffsetBankFeedEntryId'] ?? null),
            resolutionNote: AzureSqlValueMapper::nullableString($row['ResolutionNote'] ?? null),
            resolvedByUserId: $resolvedByUserId !== null ? UserId::fromInt($resolvedByUserId) : null,
            legacyResolvedBy: AzureSqlValueMapper::nullableString($row['LegacyResolvedBy'] ?? null),
            resolvedAtUtc: AzureSqlValueMapper::nullableDateTime($row['ResolvedAtUtc'] ?? null),
            legacyUploadedBy: AzureSqlValueMapper::nullableString($row['LegacyUploadedBy'] ?? null),
            legacyBankFeedId: AzureSqlValueMapper::nullableInt($row['LegacyBankFeedId'] ?? null),
            uploadedAtUtc: AzureSqlValueMapper::requireDateTime($row['UploadedAtUtc'] ?? null, 'UploadedAtUtc'),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
        );
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        $raw = AzureSqlValueMapper::nullableString($value);
        if ($raw === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        return $parsed instanceof DateTimeImmutable ? $parsed : new DateTimeImmutable($raw);
    }
}
