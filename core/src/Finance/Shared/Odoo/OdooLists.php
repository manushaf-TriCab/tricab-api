<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

/**
 * The four Odoo lists the finance apps read, as `_work-portal-entra-20261005/server/odoo_sync.py`
 * defines them (`LISTS`, `_vendor`, `_company`, `_cost_centre`, `_account`): which model,
 * which fields to ask for, which domain, which field is the record's "code", and how a
 * row from Odoo becomes the payload the apps read. Read-only: nothing here writes to Odoo.
 */
final class OdooLists
{
    public const COMPANIES = 'companies';

    public const VENDORS = 'vendors';

    public const COST_CENTRES = 'cost-centres';

    public const ACCOUNTS = 'accounts';

    /** Not in KEYS or all(): synced only for Tri-AP (see OdooSyncService), read by Ap2OdooPostingService. */
    public const TAXES = 'taxes';

    /** Odoo 19 `account.tax` fields the taxes list asks for. */
    public const TAX_FIELDS = ['name', 'description', 'amount', 'amount_type', 'type_tax_use', 'company_id', 'active', 'write_date'];

    public const KEYS = [self::COMPANIES, self::VENDORS, self::COST_CENTRES, self::ACCOUNTS];

    /**
     * @return list<OdooListSpec>
     */
    public static function all(): array
    {
        return [
            new OdooListSpec(
                self::COMPANIES,
                'res.company',
                ['name', 'currency_id', 'country_id', 'vat', 'active', 'write_date'],
                null,
                static fn (array $companyIds): array => [['id', 'in', $companyIds]],
                static fn (array $row): array => self::company($row),
            ),
            new OdooListSpec(
                self::VENDORS,
                'res.partner',
                ['name', 'ref', 'email', 'vat', 'phone', 'company_id', 'is_company', 'parent_id', 'country_id', 'active', 'write_date'],
                'ref',
                static fn (array $companyIds): array => [['supplier_rank', '>', 0]],
                static fn (array $row): array => self::vendor($row),
            ),
            new OdooListSpec(
                self::COST_CENTRES,
                'account.analytic.account',
                ['name', 'code', 'plan_id', 'company_id', 'active', 'write_date'],
                'code',
                static fn (array $companyIds): array => [],
                static fn (array $row): array => self::costCentre($row),
            ),
            new OdooListSpec(
                self::ACCOUNTS,
                'account.account',
                ['code', 'name', 'account_type', 'company_ids', 'active', 'write_date'],
                'code',
                static fn (array $companyIds): array => [],
                static fn (array $row): array => self::account($row),
            ),
        ];
    }

    /** Purchase taxes of the configured companies. */
    public static function taxes(): OdooListSpec
    {
        return new OdooListSpec(
            self::TAXES,
            'account.tax',
            self::TAX_FIELDS,
            null,
            static fn (array $companyIds): array => [['type_tax_use', '=', 'purchase'], ['company_id', 'in', $companyIds]],
            static fn (array $row): array => self::tax($row),
        );
    }

    public static function find(string $key): ?OdooListSpec
    {
        foreach ([...self::all(), self::taxes()] as $spec) {
            if ($spec->key === $key) {
                return $spec;
            }
        }

        return null;
    }

    /**
     * Odoo's many2one: `[id, "name"]`, or `false` when empty.
     *
     * @return array{0: int|null, 1: string}
     */
    private static function many2one(mixed $value): array
    {
        if (is_array($value) && $value !== [] && array_key_exists(0, $value) && is_numeric($value[0])) {
            return [(int) $value[0], self::text($value[1] ?? '')];
        }

        return [null, ''];
    }

    /** Odoo sends `false` for an empty char field. */
    private static function text(mixed $value): string
    {
        return $value === null || $value === false || !is_scalar($value) ? '' : trim((string) $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function vendor(array $row): array
    {
        [$companyId, $companyName] = self::many2one($row['company_id'] ?? null);
        [$parentId, $parentName] = self::many2one($row['parent_id'] ?? null);
        [, $country] = self::many2one($row['country_id'] ?? null);

        return [
            'name' => self::text($row['name'] ?? null),
            'ref' => self::text($row['ref'] ?? null),
            'email' => self::text($row['email'] ?? null),
            'vat' => self::text($row['vat'] ?? null),
            'phone' => self::text($row['phone'] ?? null),
            'company_id' => $companyId,
            'company_name' => $companyName,
            'is_company' => (bool) ($row['is_company'] ?? false),
            'parent_id' => $parentId,
            'parent_name' => $parentName,
            'country' => $country,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function company(array $row): array
    {
        [, $currency] = self::many2one($row['currency_id'] ?? null);
        [, $country] = self::many2one($row['country_id'] ?? null);

        return [
            'name' => self::text($row['name'] ?? null),
            'currency' => $currency,
            'country' => $country,
            'vat' => self::text($row['vat'] ?? null),
            'company_id' => (int) ($row['id'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function costCentre(array $row): array
    {
        [$companyId, $companyName] = self::many2one($row['company_id'] ?? null);
        [, $plan] = self::many2one($row['plan_id'] ?? null);

        return [
            'name' => self::text($row['name'] ?? null),
            'code' => self::text($row['code'] ?? null),
            'plan' => $plan,
            'company_id' => $companyId,
            'company_name' => $companyName,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function account(array $row): array
    {
        $companyIds = [];
        $raw = $row['company_ids'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_numeric($value)) {
                $companyIds[] = (int) $value;
            }
        }

        return [
            'name' => self::text($row['name'] ?? null),
            'code' => self::text($row['code'] ?? null),
            'account_type' => self::text($row['account_type'] ?? null),
            'company_ids' => $companyIds,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function tax(array $row): array
    {
        [$companyId, $companyName] = self::many2one($row['company_id'] ?? null);
        $amount = $row['amount'] ?? 0;

        return [
            'name' => self::text($row['name'] ?? null),
            'description' => self::text($row['description'] ?? null),
            'amount' => is_numeric($amount) ? (float) $amount : 0.0,
            'amount_type' => self::text($row['amount_type'] ?? null),
            'type_tax_use' => self::text($row['type_tax_use'] ?? null),
            'company_id' => $companyId,
            'company_name' => $companyName,
        ];
    }
}
