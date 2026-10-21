<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Persistence;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Rfq\Application\DTO\RfqContactOption;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Contract\RfqCustomerDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Domain\Service\SalesOfficePolicy;

/**
 * The header lookups over the platform's synced customer book
 * (`customer.Customers`, `customer.CustomerContacts`), matching the source's
 * queries on `cust` and `contacts`: code prefix or name contains, every
 * status, by code; contacts of one customer by name.
 *
 * 4js customer codes are not unique (5,897 `cust` rows share one, 28 Sep
 * 2026). The sync keeps the first as it is and names the others
 * `<code>-<cust.id>`, so the code 4js knows is `CustomerCode` less that
 * suffix, and the customer is named by `LegacyCustomerId` (`cust.id`), as the
 * source names it by `customer_id`. A code that merely ends in digits after a
 * dash keeps them.
 */
final class AzureSqlRfqCustomerDirectory implements RfqCustomerDirectory
{
    private const FOURJS_CODE = <<<'SQL'
        CASE WHEN c.[CustomerCode] LIKE '%-' + CONVERT(VARCHAR(20), c.[LegacyCustomerId])
             THEN LEFT(c.[CustomerCode], LEN(c.[CustomerCode]) - LEN(CONVERT(VARCHAR(20), c.[LegacyCustomerId])) - 1)
             ELSE ISNULL(c.[CustomerCode], '') END
        SQL;

    private const SEARCH = <<<'SQL'
        SELECT TOP (:limit) c.[LegacyCustomerId], {fourjsCode} AS [FourjsCode], c.[CustomerName], c.[TerritoryCode]
        FROM [customer].[Customers] c
        WHERE c.[LegacyCustomerId] IS NOT NULL
          AND (c.[CustomerCode] LIKE :prefix ESCAPE '!' OR c.[CustomerName] LIKE :contains ESCAPE '!')
        ORDER BY [FourjsCode], c.[LegacyCustomerId]
        SQL;

    private const CONTACTS = <<<'SQL'
        SELECT TOP (:limit) cc.[LegacyContactId], cc.[DisplayName], cc.[EmailAddress], cc.[PhoneNumber], cc.[PositionTitle]
        FROM [customer].[CustomerContacts] cc
        INNER JOIN [customer].[Customers] c ON c.[CustomerId] = cc.[CustomerId]
        WHERE c.[LegacyCustomerId] = :customerId AND cc.[LegacyContactId] IS NOT NULL
        ORDER BY cc.[DisplayName]
        SQL;

    private const FIND = <<<'SQL'
        SELECT TOP (1) c.[LegacyCustomerId], {fourjsCode} AS [FourjsCode], c.[CustomerName], c.[TerritoryCode]
        FROM [customer].[Customers] c
        WHERE c.[LegacyCustomerId] = :customerId
        SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $like = self::escapeLike($query);
        try {
            $rows = $this->connection->fetchAll(str_replace('{fourjsCode}', self::FOURJS_CODE, self::SEARCH), [
                'limit' => $limit,
                'prefix' => $like . '%',
                'contains' => '%' . $like . '%',
            ]);
        } catch (Throwable $failure) {
            throw new RfqLookupUnavailable('customer', $failure);
        }

        return array_map(self::customer(...), $rows);
    }

    public function find(int $customerId): ?RfqCustomerOption
    {
        try {
            $rows = $this->connection->fetchAll(str_replace('{fourjsCode}', self::FOURJS_CODE, self::FIND), ['customerId' => $customerId]);
        } catch (Throwable $failure) {
            throw new RfqLookupUnavailable('customer', $failure);
        }

        return $rows === [] ? null : self::customer($rows[0]);
    }

    public function contacts(int $customerId, int $limit): array
    {
        try {
            $rows = $this->connection->fetchAll(self::CONTACTS, ['limit' => $limit, 'customerId' => $customerId]);
        } catch (Throwable $failure) {
            throw new RfqLookupUnavailable('contact', $failure);
        }

        $options = [];
        foreach ($rows as $row) {
            $options[] = new RfqContactOption(
                AzureSqlValueMapper::stringValue($row['LegacyContactId']),
                AzureSqlValueMapper::nullableString($row['DisplayName']) ?? '',
                AzureSqlValueMapper::nullableString($row['EmailAddress']),
                AzureSqlValueMapper::nullableString($row['PhoneNumber']),
                AzureSqlValueMapper::nullableString($row['PositionTitle'] ?? null),
            );
        }

        return $options;
    }

    /** @param array<string, mixed> $row */
    private static function customer(array $row): RfqCustomerOption
    {
        $territory = AzureSqlValueMapper::nullableString($row['TerritoryCode']);

        return new RfqCustomerOption(
            AzureSqlValueMapper::intValue($row['LegacyCustomerId']),
            AzureSqlValueMapper::stringValue($row['FourjsCode']),
            AzureSqlValueMapper::nullableString($row['CustomerName']) ?? '',
            $territory,
            SalesOfficePolicy::forTerritory($territory),
        );
    }

    /**
     * `%`, `_` and `[` are text in a customer's name, not patterns. The escape
     * character is `!`, not a backslash: PDO's placeholder parser reads `'\'`
     * as an escaped quote and loses every parameter after it.
     */
    private static function escapeLike(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']);
    }
}
