<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Odoo;

use TriCab\Core\Finance\Ap2\Contract\Ap2OdooLedgerSource;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooLedger;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPayableLine;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooValues as V;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooVendor;
use TriCab\Core\Finance\Ap2\Service\Ap2Time;
use TriCab\Core\Finance\Shared\Odoo\OdooClient;

/**
 * {@see Ap2OdooLedgerSource} over the shared read-only {@see OdooClient} (the companies in ODOO_COMPANY_IDS). Four
 * reads: the companies, the open payable lines, the posted vendor bills (for who the suppliers are), and those
 * partners.
 */
final class HttpAp2OdooLedgerSource implements Ap2OdooLedgerSource
{
    /** A posted line on a payable account. */
    private const PAYABLE = [['account_id.account_type', '=', 'liability_payable'], ['parent_state', '=', 'posted']];

    private const LINE_FIELDS = [
        'id', 'move_id', 'move_name', 'ref', 'move_type', 'journal_id', 'partner_id', 'company_id', 'company_currency_id', 'currency_id',
        'date', 'date_maturity', 'balance', 'amount_residual', 'amount_currency', 'amount_residual_currency', 'reconciled',
    ];

    /** Entity codes by company currency; a company in another currency keeps its name. */
    private const ENTITY_BY_CURRENCY = ['AUD' => 'AU', 'NZD' => 'NZ', 'SGD' => 'SG', 'USD' => 'US'];

    public function __construct(
        private readonly OdooClient $odoo,
        private readonly Ap2Time $time,
    ) {
    }

    public function ledger(bool $fresh = false): Ap2OdooLedger
    {
        $entities = [];
        foreach ($this->odoo->searchRead('res.company', [], ['id', 'name', 'currency_id']) as $row) {
            $id = V::id($row['id'] ?? null);
            if ($id !== null) {
                $entities[$id] = self::ENTITY_BY_CURRENCY[V::many2oneName($row['currency_id'] ?? null)] ?? V::text($row['name'] ?? null);
            }
        }

        $lines = array_map(self::line(...), $this->odoo->searchRead('account.move.line', [...self::PAYABLE, ['reconciled', '=', false]], self::LINE_FIELDS));

        // Who the suppliers are: any partner on a posted vendor bill or credit note, or on an open payable line.
        $companies = [];
        foreach ($this->odoo->searchRead('account.move', [['move_type', 'in', ['in_invoice', 'in_refund']], ['state', '=', 'posted']], ['id', 'commercial_partner_id', 'company_id']) as $row) {
            $partnerId = V::many2oneId($row['commercial_partner_id'] ?? null);
            $companyId = V::many2oneId($row['company_id'] ?? null);
            if ($partnerId !== null && $companyId !== null) {
                $companies[$partnerId][$companyId] = true;
            }
        }
        foreach ($lines as $line) {
            if ($line->partnerId !== null) {
                $companies[$line->partnerId][$line->companyId] = true;
            }
        }

        $vendors = [];
        if ($companies !== []) {
            $fields = ['id', 'name', 'email', 'phone', 'industry_id', 'property_supplier_payment_term_id', 'active'];
            foreach ($this->odoo->searchRead('res.partner', [['id', 'in', array_keys($companies)]], $fields) as $row) {
                $id = V::id($row['id'] ?? null);
                if ($id === null) {
                    continue;
                }
                $codes = array_map(static fn (int $c): string => $entities[$c] ?? (string) $c, array_keys($companies[$id] ?? []));
                sort($codes);
                $terms = V::many2oneName($row['property_supplier_payment_term_id'] ?? null);
                $vendors[$id] = new Ap2OdooVendor(
                    $id,
                    V::text($row['name'] ?? null),
                    V::text($row['email'] ?? null),
                    V::text($row['phone'] ?? null),
                    V::many2oneName($row['industry_id'] ?? null),
                    implode(', ', array_unique($codes)),
                    Ap2OdooVendor::termDays($terms),
                    $terms,
                    ($row['active'] ?? true) !== false,
                );
            }
        }

        return new Ap2OdooLedger($lines, $vendors, $entities, $this->time->now());
    }

    public function payableLines(array $partnerIds, string $onOrBefore): array
    {
        $ids = array_values(array_unique(array_filter($partnerIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return array_map(self::line(...), $this->odoo->searchRead('account.move.line', [...self::PAYABLE, ['partner_id', 'in', $ids], ['date', '<=', $onOrBefore]], self::LINE_FIELDS));
    }

    /** @param array<string, mixed> $row */
    private static function line(array $row): Ap2OdooPayableLine
    {
        $companyCurrency = V::many2oneName($row['company_currency_id'] ?? null);
        $currency = V::many2oneName($row['currency_id'] ?? null);

        return new Ap2OdooPayableLine(
            V::id($row['id'] ?? null) ?? 0,
            V::many2oneId($row['move_id'] ?? null) ?? 0,
            V::text($row['move_name'] ?? null),
            V::text($row['ref'] ?? null),
            V::text($row['move_type'] ?? null),
            V::many2oneName($row['journal_id'] ?? null),
            V::many2oneId($row['partner_id'] ?? null),
            V::many2oneName($row['partner_id'] ?? null),
            V::many2oneId($row['company_id'] ?? null) ?? 0,
            $companyCurrency,
            $currency !== '' ? $currency : $companyCurrency,
            V::date($row['date'] ?? null) ?? '',
            V::date($row['date_maturity'] ?? null),
            V::number($row['balance'] ?? null),
            V::number($row['amount_residual'] ?? null),
            V::number($row['amount_currency'] ?? null),
            V::number($row['amount_residual_currency'] ?? null),
            V::bool($row['reconciled'] ?? null),
        );
    }
}
