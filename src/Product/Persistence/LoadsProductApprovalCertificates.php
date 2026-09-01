<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Product\Application\DTO\ProductApprovalCertificateItem;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Shared APPROVALS option assignments for public and customer-view product reads.
 *
 * @phpstan-require-property \TriCab\Api\Infrastructure\Database\SqlConnection $connection
 */
trait LoadsProductApprovalCertificates
{
    /**
     * @return list<ProductApprovalCertificateItem>
     */
    private function listApprovalCertificates(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), o.[PublicId]) AS [PublicId],
    o.[OptionCode] AS [Code],
    o.[OptionLabel] AS [Name],
    o.[OptionValue] AS [Issuer],
    o.[ImageUrl],
    ISNULL(pov.[StatusCode], N'approved') AS [StatusCode],
    o.[DisplayOrder]
FROM [product].[ProductSpecificationValues] psv
INNER JOIN [product].[Products] p ON p.[ProductId] = psv.[ProductId]
INNER JOIN [product].[SpecificationDefinitions] sd
    ON sd.[SpecificationDefinitionId] = psv.[SpecificationDefinitionId]
INNER JOIN [product].[ProductSpecificationOptionValues] pov
    ON pov.[ProductSpecificationValueId] = psv.[ProductSpecificationValueId]
INNER JOIN [product].[SpecificationOptions] o
    ON o.[SpecificationOptionId] = pov.[SpecificationOptionId]
WHERE p.[PublicId] = :productPublicId
  AND psv.[DeletedAtUtc] IS NULL
  AND sd.[DeletedAtUtc] IS NULL
  AND sd.[IsActive] = 1
  AND sd.[DefinitionCode] = N'APPROVALS'
  AND o.[DeletedAtUtc] IS NULL
ORDER BY o.[DisplayOrder] ASC, o.[OptionLabel] ASC
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        $items = [];
        foreach ($rows as $row) {
            $statusCode = strtolower(AzureSqlValueMapper::stringValue($row['StatusCode'] ?? 'approved'));
            if ($statusCode !== 'pending' && $statusCode !== 'approved') {
                $statusCode = 'approved';
            }
            $items[] = new ProductApprovalCertificateItem(
                publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
                name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
                issuer: AzureSqlValueMapper::nullableString($row['Issuer'] ?? null),
                imageUrl: AzureSqlValueMapper::nullableString($row['ImageUrl'] ?? null),
                statusCode: $statusCode,
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            );
        }

        return $items;
    }
}
