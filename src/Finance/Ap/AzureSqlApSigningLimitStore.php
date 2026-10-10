<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApSigningLimitStore;
use TriCab\Core\Finance\Ap\Domain\ApSigningLimit;

/** `finance.invoice_signing_limits` (`create-finance-invoice-schema.sql`). Read only. */
final class AzureSqlApSigningLimitStore extends AzureSqlApSupport implements ApSigningLimitStore
{
    public function all(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [authority_role], [role_code], [full_name], [email], [d365_position], [po_inventory], [po_non_inventory],
                    [expense_claim], [customer_order], [credit_notes], [is_active]
               FROM [finance].[invoice_signing_limits] ORDER BY [full_name], [d365_position]',
        );

        return array_map(static fn (array $row): ApSigningLimit => new ApSigningLimit(
            self::id($row['id']),
            self::text($row['authority_role']),
            self::text($row['role_code']),
            self::text($row['full_name']),
            self::text($row['email']),
            self::text($row['d365_position']),
            self::money($row['po_inventory']),
            self::money($row['po_non_inventory']),
            self::money($row['expense_claim']),
            self::money($row['customer_order']),
            self::money($row['credit_notes']),
            self::bool($row['is_active']),
        ), $rows);
    }

    private static function money(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
