<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

use InvalidArgumentException;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;

/**
 * A DRAFT vendor bill to create in Odoo (`account.move`, `in_invoice`, or `in_refund` for a credit note). It is the
 * API form of the CSV export's rows: the same external id (`triap_<invoice id>`, `triap_<id>_credit` for the
 * credit), the same reference, dates, currency and amounts. Odoo computes the tax from the tax ids; Finance checks
 * the draft and posts it in Odoo.
 */
final readonly class Ap2OdooBill
{
    public const IN_INVOICE = 'in_invoice';
    public const IN_REFUND = 'in_refund';

    /** @param list<Ap2OdooBillLine> $lines */
    public function __construct(
        public string $invoiceId,
        public string $externalId,
        public string $moveType,
        public int $partnerId,
        public int $companyId,
        public string $currency,
        public string $ref,
        public ?string $invoiceDate,
        public ?string $dueDate,
        public string $origin,
        public array $lines,
    ) {
        if ($moveType !== self::IN_INVOICE && $moveType !== self::IN_REFUND) {
            throw new InvalidArgumentException('A vendor bill is in_invoice or in_refund.');
        }
        if (preg_match('/^triap_[0-9a-f]{32}(_credit)?$/', $externalId) !== 1) {
            throw new InvalidArgumentException('The external id is triap_<invoice id>, with _credit for a credit note.');
        }
        if ($partnerId <= 0 || $companyId <= 0) {
            throw new InvalidArgumentException('A vendor bill needs the Odoo vendor and company.');
        }
        if (!in_array($currency, Ap2Invoice::CURRENCIES, true)) {
            throw new InvalidArgumentException('The currency must be one of ' . implode(', ', Ap2Invoice::CURRENCIES) . '.');
        }
        if ($lines === []) {
            throw new InvalidArgumentException('A vendor bill needs a line.');
        }
    }

    /**
     * The bill, and the credit note when the invoice has one, as the CSV export builds its two rows: one line each,
     * at the net amount, on the invoice's account, tax and cost centre.
     *
     * @param list<int> $taxIds
     * @return list<self>
     */
    public static function forInvoice(Ap2Invoice $b, int $partnerId, int $companyId, ?int $accountId, array $taxIds, ?int $analyticAccountId): array
    {
        $bills = [new self(
            $b->id,
            'triap_' . $b->id,
            self::IN_INVOICE,
            $partnerId,
            $companyId,
            $b->currency,
            $b->number,
            $b->issued,
            $b->due,
            $b->po !== '' ? $b->po : $b->sourceDoc,
            [new Ap2OdooBillLine(trim($b->number . ' ' . $b->kind), 1.0, round($b->net, 2), $accountId, $taxIds, $analyticAccountId)],
        )];
        if ($b->credit > 0) {
            $bills[] = new self(
                $b->id,
                'triap_' . $b->id . '_credit',
                self::IN_REFUND,
                $partnerId,
                $companyId,
                $b->currency,
                $b->creditRef !== '' ? $b->creditRef : $b->number . ' credit',
                $b->issued,
                $b->due,
                $b->number,
                [new Ap2OdooBillLine(trim(($b->creditRef !== '' ? $b->creditRef : 'Credit') . ' against ' . $b->number), 1.0, round($b->creditNet, 2), $accountId, $taxIds, $analyticAccountId)],
            );
        }

        return $bills;
    }

    /**
     * The `account.move` values for `create`, with the currency's Odoo id. State is left to Odoo's default: draft.
     *
     * @return array<string, mixed>
     */
    public function values(int $currencyId): array
    {
        $values = [
            'move_type' => $this->moveType,
            'partner_id' => $this->partnerId,
            'company_id' => $this->companyId,
            'currency_id' => $currencyId,
            'ref' => $this->ref,
            'invoice_origin' => $this->origin,
            'invoice_line_ids' => array_map(static fn (Ap2OdooBillLine $l): array => $l->command(), $this->lines),
        ];
        if ($this->invoiceDate !== null) {
            $values['invoice_date'] = $this->invoiceDate;
        }
        if ($this->dueDate !== null) {
            $values['invoice_date_due'] = $this->dueDate;
        }

        return $values;
    }
}
