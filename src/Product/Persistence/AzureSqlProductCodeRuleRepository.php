<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Exception\DuplicateProductCode;
use TriCab\Core\Product\Contract\ProductCodeRuleRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Azure SQL repository for product code rule sets and primary code assignment.
 * Tables: product.ProductCodeRuleSets, product.ProductCodeRuleSegments, product.ProductCodes.
 */
final class AzureSqlProductCodeRuleRepository implements ProductCodeRuleRepository
{
    use AzureSqlTransaction;
    use UniqueConstraintTranslator;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listRuleSets(bool $activeOnly = true): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), rs.[PublicId]) AS [PublicId],
    rs.[RuleSetCode],
    rs.[RuleSetName]
FROM [product].[ProductCodeRuleSets] rs
WHERE rs.[DeletedAtUtc] IS NULL
SQL;
        if ($activeOnly) {
            $sql .= ' AND rs.[IsActive] = 1';
        }
        $sql .= ' ORDER BY rs.[RuleSetName]';

        $ruleSets = $this->connection->fetchAll($sql);
        $result = [];

        foreach ($ruleSets as $ruleSetRow) {
            $segmentRows = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    seg.[SegmentCode],
    seg.[SegmentName],
    seg.[SegmentOrder],
    seg.[SegmentTypeCode],
    seg.[LiteralValue],
    seg.[IsRequired],
    seg.[SeparatorAfter]
FROM [product].[ProductCodeRuleSegments] seg
INNER JOIN [product].[ProductCodeRuleSets] rs ON rs.[ProductCodeRuleSetId] = seg.[ProductCodeRuleSetId]
WHERE CONVERT(NVARCHAR(36), rs.[PublicId]) = :ruleSetPublicId
  AND seg.[DeletedAtUtc] IS NULL
ORDER BY seg.[SegmentOrder]
SQL,
                [':ruleSetPublicId' => AzureSqlValueMapper::stringValue($ruleSetRow['PublicId'] ?? '')],
            );

            $segments = array_map(
                static fn (array $row): array => [
                    'segmentCode' => AzureSqlValueMapper::stringValue($row['SegmentCode'] ?? ''),
                    'segmentName' => AzureSqlValueMapper::stringValue($row['SegmentName'] ?? ''),
                    'segmentOrder' => AzureSqlValueMapper::intValue($row['SegmentOrder'] ?? 0),
                    'segmentTypeCode' => AzureSqlValueMapper::stringValue($row['SegmentTypeCode'] ?? ''),
                    'literalValue' => AzureSqlValueMapper::nullableString($row['LiteralValue'] ?? null),
                    'isRequired' => AzureSqlValueMapper::boolValue($row['IsRequired'] ?? true),
                    'separatorAfter' => AzureSqlValueMapper::nullableString($row['SeparatorAfter'] ?? null),
                ],
                $segmentRows,
            );

            $result[] = [
                'publicId' => AzureSqlValueMapper::stringValue($ruleSetRow['PublicId'] ?? ''),
                'ruleSetCode' => AzureSqlValueMapper::stringValue($ruleSetRow['RuleSetCode'] ?? ''),
                'ruleSetName' => AzureSqlValueMapper::stringValue($ruleSetRow['RuleSetName'] ?? ''),
                'segments' => $segments,
            ];
        }

        return $result;
    }

    public function preview(
        string $ruleSetPublicId,
        array $segmentValues,
    ): array {
        $segments = $this->loadSegments($ruleSetPublicId);
        $assembled = '';
        $explanationParts = [];

        foreach ($segments as $segment) {
            $value = match ($segment['segmentTypeCode']) {
                'literal' => $segment['literalValue'],
                default => $segmentValues[$segment['segmentCode']] ?? null,
            };

            if (($value === null || $value === '') && $segment['isRequired']) {
                throw ProductPersistenceException::operationFailed(
                    sprintf('Required segment "%s" is missing.', $segment['segmentCode']),
                );
            }

            if ($value === null || $value === '') {
                continue;
            }

            $assembled .= $value;
            if ($segment['separatorAfter'] !== null) {
                $assembled .= $segment['separatorAfter'];
            }
            $explanationParts[] = sprintf('%s: %s', $segment['segmentName'], $value);
        }

        return [
            'code' => $assembled,
            'explanation' => implode('; ', $explanationParts),
        ];
    }

    public function assignPrimaryCode(
        ProductPublicId $productPublicId,
        ProductCodeValue $code,
        ?string $explanation,
        ?string $ruleSetPublicId,
        UserId $actorUserId,
    ): void {
        $this->translatingUniqueConstraintViolation(
            fn () => $this->doAssignPrimaryCode($productPublicId, $code, $explanation, $ruleSetPublicId, $actorUserId),
            fn () => DuplicateProductCode::forCode($code->toString()),
        );
    }

    private function doAssignPrimaryCode(
        ProductPublicId $productPublicId,
        ProductCodeValue $code,
        ?string $explanation,
        ?string $ruleSetPublicId,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $code, $explanation, $ruleSetPublicId, $actorUserId): void {
                $productId = $this->requireProductId($productPublicId);
                $ruleSetId = $ruleSetPublicId !== null ? $this->requireRuleSetId($ruleSetPublicId) : null;

                $this->connection->execute(
                    <<<'SQL'
UPDATE [product].[ProductCodes]
SET
    [IsPrimary] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [ProductId] = :productId
  AND [IsPrimary] = 1
  AND [DeletedAtUtc] IS NULL
SQL,
                    [
                        ':productId' => $productId,
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                $existing = $this->connection->fetchAll(
                    <<<'SQL'
SELECT [ProductCodeId]
FROM [product].[ProductCodes]
WHERE [ProductId] = :productId
  AND [CodeValue] = :codeValue
  AND [DeletedAtUtc] IS NULL
SQL,
                    [
                        ':productId' => $productId,
                        ':codeValue' => $code->toString(),
                    ],
                );

                if ($existing === []) {
                    $this->connection->execute(
                        <<<'SQL'
INSERT INTO [product].[ProductCodes] (
    [ProductId],
    [CodeValue],
    [CodeExplanation],
    [IsPrimary],
    [SourceRuleSetId],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :productId,
    :codeValue,
    :codeExplanation,
    1,
    :sourceRuleSetId,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                        [
                            ':productId' => $productId,
                            ':codeValue' => $code->toString(),
                            ':codeExplanation' => $explanation,
                            ':sourceRuleSetId' => $ruleSetId,
                            ':createdByUserId' => $actorUserId->toInt(),
                            ':updatedByUserId' => $actorUserId->toInt(),
                        ],
                    );
                } else {
                    $this->connection->execute(
                        <<<'SQL'
UPDATE [product].[ProductCodes]
SET
    [IsPrimary] = 1,
    [CodeExplanation] = :codeExplanation,
    [SourceRuleSetId] = :sourceRuleSetId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [ProductCodeId] = :productCodeId
SQL,
                        [
                            ':productCodeId' => AzureSqlValueMapper::intValue($existing[0]['ProductCodeId'] ?? 0),
                            ':codeExplanation' => $explanation,
                            ':sourceRuleSetId' => $ruleSetId,
                            ':updatedByUserId' => $actorUserId->toInt(),
                        ],
                    );
                }
            },
        );
    }

    /**
     * @return list<array{
     *   segmentCode: string,
     *   segmentName: string,
     *   segmentOrder: int,
     *   segmentTypeCode: string,
     *   literalValue: ?string,
     *   isRequired: bool,
     *   separatorAfter: ?string
     * }>
     */
    private function loadSegments(string $ruleSetPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    seg.[SegmentCode],
    seg.[SegmentName],
    seg.[SegmentOrder],
    seg.[SegmentTypeCode],
    seg.[LiteralValue],
    seg.[IsRequired],
    seg.[SeparatorAfter]
FROM [product].[ProductCodeRuleSegments] seg
INNER JOIN [product].[ProductCodeRuleSets] rs ON rs.[ProductCodeRuleSetId] = seg.[ProductCodeRuleSetId]
WHERE rs.[PublicId] = :ruleSetPublicId
  AND seg.[DeletedAtUtc] IS NULL
  AND rs.[DeletedAtUtc] IS NULL
ORDER BY seg.[SegmentOrder]
SQL,
            [':ruleSetPublicId' => $ruleSetPublicId],
        );

        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Product code rule set', $ruleSetPublicId);
        }

        return array_map(
            static fn (array $row): array => [
                'segmentCode' => AzureSqlValueMapper::stringValue($row['SegmentCode'] ?? ''),
                'segmentName' => AzureSqlValueMapper::stringValue($row['SegmentName'] ?? ''),
                'segmentOrder' => AzureSqlValueMapper::intValue($row['SegmentOrder'] ?? 0),
                'segmentTypeCode' => AzureSqlValueMapper::stringValue($row['SegmentTypeCode'] ?? ''),
                'literalValue' => AzureSqlValueMapper::nullableString($row['LiteralValue'] ?? null),
                'isRequired' => AzureSqlValueMapper::boolValue($row['IsRequired'] ?? true),
                'separatorAfter' => AzureSqlValueMapper::nullableString($row['SeparatorAfter'] ?? null),
            ],
            $rows,
        );
    }

    private function requireRuleSetId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductCodeRuleSetId] FROM [product].[ProductCodeRuleSets] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Product code rule set', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductCodeRuleSetId'] ?? 0);
    }

    private function requireProductId(ProductPublicId $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId->toString()],
        );
        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($publicId->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }
}
