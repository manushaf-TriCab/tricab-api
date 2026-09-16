<?php

declare(strict_types=1);

namespace TriCab\Api\LogNote\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\LogNote\Application\DTO\LogNoteDetails;
use TriCab\Core\LogNote\Application\DTO\LogNoteListCriteria;
use TriCab\Core\LogNote\Application\DTO\LogNoteListResult;
use TriCab\Core\LogNote\Application\DTO\LogNoteSummary;
use TriCab\Core\LogNote\Contract\LogNoteRepository;
use TriCab\Core\LogNote\Domain\Entity\LogNote;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteBody;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteCategory;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteInteractionType;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;

final class AzureSqlLogNoteRepository implements LogNoteRepository
{
    use AzureSqlTransaction;

    private const SELECT_COLUMNS = <<<'SQL'
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [CustomerPublicId],
    c.[CustomerCode],
    c.[CustomerName],
    CONVERT(NVARCHAR(36), ct.[PublicId]) AS [ContactPublicId],
    n.[ContactNameSnapshot],
    n.[ContactPositionSnapshot],
    n.[InteractionTypeCode],
    n.[CategoryCode],
    n.[Body],
    n.[CallResultCode],
    n.[IsAiGenerated],
    CONVERT(VARCHAR(33), n.[FollowUpAt], 127) AS [FollowUpAt],
    CONVERT(NVARCHAR(36), u.[PublicId]) AS [CreatedByUserPublicId],
    COALESCE(NULLIF(u.[DisplayName], N''), u.[EmailAddress]) AS [CreatedByDisplayName],
    CONVERT(VARCHAR(33), n.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    c.[CountryCode],
    c.[TerritoryCode]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function save(LogNote $note): LogNoteDetails
    {
        $this->runInTransaction($this->connection, function () use ($note): void {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [customer].[LogNotes] (
    [PublicId],
    [CustomerId],
    [CustomerContactId],
    [ContactNameSnapshot],
    [ContactPositionSnapshot],
    [InteractionTypeCode],
    [CategoryCode],
    [Body],
    [CallResultCode],
    [FollowUpAt],
    [IsAiGenerated],
    [CreatedByUserId],
    [CreatedAtUtc],
    [LegacyCustCallId],
    [LegacySalesRepLogin]
)
VALUES (
    :publicId,
    :customerId,
    :customerContactId,
    :contactNameSnapshot,
    :contactPositionSnapshot,
    :interactionTypeCode,
    :categoryCode,
    :body,
    :callResultCode,
    :followUpAt,
    :isAiGenerated,
    :createdByUserId,
    :createdAtUtc,
    :legacyCustCallId,
    :legacySalesRepLogin
)
SQL,
                [
                    ':publicId' => $note->publicId()->toString(),
                    ':customerId' => $note->customerId()->toInt(),
                    ':customerContactId' => $note->customerContactId()?->toInt(),
                    ':contactNameSnapshot' => $note->contactNameSnapshot(),
                    ':contactPositionSnapshot' => $note->contactPositionSnapshot(),
                    ':interactionTypeCode' => $note->interactionType()->toString(),
                    ':categoryCode' => $note->category()->toString(),
                    ':body' => $note->body()->toString(),
                    ':callResultCode' => $note->callResultCode(),
                    ':followUpAt' => $note->followUpAt() !== null
                        ? AzureSqlValueMapper::formatDateTime($note->followUpAt())
                        : null,
                    ':isAiGenerated' => $note->isAiGenerated() ? 1 : 0,
                    ':createdByUserId' => $note->createdByUserId()->toInt(),
                    ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($note->createdAtUtc()),
                    ':legacyCustCallId' => $note->legacyCustCallId(),
                    ':legacySalesRepLogin' => $note->legacySalesRepLogin(),
                ],
            );
        });

        $details = $this->findDetailsByPublicId($note->publicId());
        if ($details === null) {
            throw new \RuntimeException('Log note was inserted but could not be reloaded.');
        }

        return $details;
    }

    public function findDetailsByPublicId(LogNotePublicId $publicId): ?LogNoteDetails
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [customer].[LogNotes] n
INNER JOIN [customer].[Customers] c ON c.[CustomerId] = n.[CustomerId]
INNER JOIN [identity].[Users] u ON u.[UserId] = n.[CreatedByUserId]
LEFT JOIN [customer].[CustomerContacts] ct ON ct.[CustomerContactId] = n.[CustomerContactId]
WHERE n.[PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : $this->hydrateDetails($rows[0]);
    }

    public function list(LogNoteListCriteria $criteria): LogNoteListResult
    {
        [$whereSql, $params] = $this->where($criteria);
        $countRows = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Total]
FROM [customer].[LogNotes] n
INNER JOIN [customer].[Customers] c ON c.[CustomerId] = n.[CustomerId]
INNER JOIN [identity].[Users] u ON u.[UserId] = n.[CreatedByUserId]
LEFT JOIN [customer].[CustomerContacts] ct ON ct.[CustomerContactId] = n.[CustomerContactId]
' . $whereSql,
            $params,
        );
        $total = AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        $order = $criteria->normalizedSortDirection() === LogNoteListCriteria::SORT_ASC ? 'ASC' : 'DESC';
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $params[':offset'] = $offset;
        $params[':pageSize'] = $criteria->pageSize;

        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [customer].[LogNotes] n
INNER JOIN [customer].[Customers] c ON c.[CustomerId] = n.[CustomerId]
INNER JOIN [identity].[Users] u ON u.[UserId] = n.[CreatedByUserId]
LEFT JOIN [customer].[CustomerContacts] ct ON ct.[CustomerContactId] = n.[CustomerContactId]
' . $whereSql . '
ORDER BY n.[CreatedAtUtc] ' . $order . ', n.[LogNoteId] ' . $order . '
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY',
            $params,
        );

        $items = array_map(
            fn (array $row): LogNoteSummary => $this->hydrateDetails($row)->toSummary(),
            $rows,
        );

        return new LogNoteListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    /**
     * @return array{0: string, 1: array<string, bool|float|int|string|null>}
     */
    private function where(LogNoteListCriteria $criteria): array
    {
        $clauses = [];
        /** @var array<string, bool|float|int|string|null> $params */
        $params = [];

        if ($criteria->customerPublicId !== null) {
            $clauses[] = 'c.[PublicId] = :customerPublicId';
            $params[':customerPublicId'] = $criteria->customerPublicId;
        }
        if ($criteria->customerCode !== null) {
            $clauses[] = 'c.[CustomerCode] = :customerCode';
            $params[':customerCode'] = $criteria->customerCode;
        }
        if ($criteria->interactionType !== null) {
            $clauses[] = 'n.[InteractionTypeCode] = :interactionType';
            $params[':interactionType'] = $criteria->interactionType;
        }
        if ($criteria->category !== null) {
            $clauses[] = 'n.[CategoryCode] = :category';
            $params[':category'] = strtolower($criteria->category);
        }
        if ($criteria->country !== null) {
            $clauses[] = 'c.[CountryCode] = :country';
            $params[':country'] = $criteria->country;
        }
        if ($criteria->territory !== null) {
            $clauses[] = 'c.[TerritoryCode] = :territory';
            $params[':territory'] = $criteria->territory;
        }
        if ($criteria->createdBy !== null) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $criteria->createdBy) === 1) {
                $clauses[] = 'u.[PublicId] = :createdByPublicId';
                $params[':createdByPublicId'] = strtolower($criteria->createdBy);
            } else {
                $clauses[] = '(u.[DisplayName] LIKE :createdByLike OR u.[EmailAddress] LIKE :createdByLike)';
                $params[':createdByLike'] = '%' . $criteria->createdBy . '%';
            }
        }

        $from = $this->parseBoundary($criteria->from, false);
        if ($from !== null) {
            $clauses[] = 'n.[CreatedAtUtc] >= :fromUtc';
            $params[':fromUtc'] = AzureSqlValueMapper::formatDateTime($from);
        }
        $to = $this->parseBoundary($criteria->to, true);
        if ($to !== null) {
            $clauses[] = 'n.[CreatedAtUtc] <= :toUtc';
            $params[':toUtc'] = AzureSqlValueMapper::formatDateTime($to);
        }

        $search = $criteria->normalizedSearch();
        if ($search !== null) {
            $clauses[] = '(n.[Body] LIKE :searchLike OR n.[ContactNameSnapshot] LIKE :searchLike OR c.[CustomerName] LIKE :searchLike OR c.[CustomerCode] LIKE :searchLike)';
            $params[':searchLike'] = '%' . $search . '%';
        }

        $sql = $clauses === [] ? '' : ('WHERE ' . implode(' AND ', $clauses));

        return [$sql, $params];
    }

    private function parseBoundary(?string $value, bool $endOfDay): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                $time = $endOfDay ? '23:59:59.999999' : '00:00:00.000000';

                return new DateTimeImmutable($raw . ' ' . $time, new DateTimeZone('UTC'));
            }

            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDetails(array $row): LogNoteDetails
    {
        $interaction = LogNoteInteractionType::fromString(AzureSqlValueMapper::stringValue($row['InteractionTypeCode'] ?? ''));
        $category = LogNoteCategory::fromString(AzureSqlValueMapper::stringValue($row['CategoryCode'] ?? ''));
        $body = AzureSqlValueMapper::stringValue($row['Body'] ?? '');
        $followUp = AzureSqlValueMapper::nullableDateTime($row['FollowUpAt'] ?? null);
        $created = AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc');

        $preview = LogNoteBody::fromString($body);

        return new LogNoteDetails(
            publicId: strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            customerPublicId: strtolower(AzureSqlValueMapper::stringValue($row['CustomerPublicId'] ?? '')),
            customerCode: AzureSqlValueMapper::nullableString($row['CustomerCode'] ?? null),
            customerName: AzureSqlValueMapper::stringValue($row['CustomerName'] ?? ''),
            contactPublicId: ($contactId = AzureSqlValueMapper::nullableString($row['ContactPublicId'] ?? null)) !== null
                ? strtolower($contactId)
                : null,
            contactName: AzureSqlValueMapper::nullableString($row['ContactNameSnapshot'] ?? null),
            contactPosition: AzureSqlValueMapper::nullableString($row['ContactPositionSnapshot'] ?? null),
            interactionTypeCode: $interaction->toString(),
            interactionTypeLabel: $interaction->label(),
            categoryCode: $category->toString(),
            categoryLabel: $category->label(),
            body: $body,
            bodyPreview: $preview->preview(),
            callResultCode: AzureSqlValueMapper::nullableString($row['CallResultCode'] ?? null),
            isAiGenerated: AzureSqlValueMapper::boolValue($row['IsAiGenerated'] ?? 0),
            followUpAt: $followUp !== null ? AzureSqlValueMapper::formatIso8601($followUp) : null,
            createdByUserPublicId: strtolower(AzureSqlValueMapper::stringValue($row['CreatedByUserPublicId'] ?? '')),
            createdByDisplayName: AzureSqlValueMapper::stringValue($row['CreatedByDisplayName'] ?? ''),
            createdAtUtc: AzureSqlValueMapper::formatIso8601($created),
            countryCode: AzureSqlValueMapper::nullableString($row['CountryCode'] ?? null),
            territoryCode: AzureSqlValueMapper::nullableString($row['TerritoryCode'] ?? null),
        );
    }
}
