<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Odoo;

use DateTimeImmutable;
use InvalidArgumentException;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooGateway;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooRecordWriter;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBankAccount;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillState;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooGatewayFields as F;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPaymentRef;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPoLine;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostedBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPurchaseOrder;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooRates;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooReceipt;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooValues as V;
use TriCab\Core\Finance\Shared\Odoo\OdooClient;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * {@see Ap2OdooGateway} over Odoo 19's JSON-2 API: reads through the shared read-only {@see OdooClient}
 * (`search_read`, the companies in ODOO_COMPANY_IDS, archived records included), the one write through
 * {@see Ap2OdooRecordWriter}, and only while `$postingEnabled` (FINANCE_AP2_ODOO_POSTING_ENABLED).
 *
 * Rates are the AUD company's (`$audCompanyId`): Odoo keeps rates per company against its currency, so another
 * company's rates would be per NZD or SGD.
 */
final class HttpAp2OdooGateway implements Ap2OdooGateway
{
    /** How far back a rate may be: Odoo's rates are not always updated daily. */
    private const RATE_WINDOW_DAYS = 400;

    public function __construct(
        private readonly OdooClient $odoo,
        private readonly Ap2OdooRecordWriter $writer,
        private readonly ?int $audCompanyId,
        private readonly bool $postingEnabled,
    ) {
    }

    public function purchaseOrders(array $poNumbers): array
    {
        $numbers = array_values(array_unique(array_filter(array_map('trim', $poNumbers), static fn (string $n): bool => $n !== '')));
        if ($numbers === []) {
            return [];
        }
        $orders = $this->odoo->searchRead('purchase.order', [['name', 'in', $numbers]], F::PURCHASE_ORDER);
        if ($orders === []) {
            return [];
        }
        $orderIds = [];
        $pickingIds = [];
        foreach ($orders as $row) {
            $id = V::id($row['id'] ?? null);
            if ($id !== null) {
                $orderIds[] = $id;
                $pickingIds = array_merge($pickingIds, V::ids($row['picking_ids'] ?? null));
            }
        }

        $linesByOrder = [];
        foreach ($this->odoo->searchRead('purchase.order.line', [['order_id', 'in', $orderIds]], F::PURCHASE_ORDER_LINE) as $row) {
            $orderId = V::many2oneId($row['order_id'] ?? null);
            $id = V::id($row['id'] ?? null);
            if ($orderId === null || $id === null || V::text($row['display_type'] ?? null) !== '') {
                continue;
            }
            $linesByOrder[$orderId][] = new Ap2OdooPoLine(
                $id,
                V::many2oneId($row['product_id'] ?? null),
                V::text($row['name'] ?? null),
                V::number($row['product_qty'] ?? null),
                V::number($row['qty_received'] ?? null),
                V::number($row['qty_invoiced'] ?? null),
                V::number($row['price_unit'] ?? null),
            );
        }

        $pickings = [];
        $pickingIds = array_values(array_unique($pickingIds));
        if ($pickingIds !== []) {
            foreach ($this->odoo->searchRead('stock.picking', [['id', 'in', $pickingIds]], F::PICKING) as $row) {
                $id = V::id($row['id'] ?? null);
                // Receipts only: a return to the vendor is an outgoing picking of the same order.
                if ($id !== null && in_array(V::text($row['picking_type_code'] ?? null), ['incoming', ''], true)) {
                    $pickings[$id] = new Ap2OdooReceipt($id, V::text($row['name'] ?? null), V::text($row['state'] ?? null), V::date($row['date_done'] ?? null));
                }
            }
        }

        $out = [];
        foreach ($orders as $row) {
            $id = V::id($row['id'] ?? null);
            $name = V::text($row['name'] ?? null);
            if ($id === null || $name === '') {
                continue;
            }
            $receipts = [];
            foreach (V::ids($row['picking_ids'] ?? null) as $pickingId) {
                if (isset($pickings[$pickingId])) {
                    $receipts[] = $pickings[$pickingId];
                }
            }
            $order = new Ap2OdooPurchaseOrder(
                $id,
                $name,
                V::text($row['state'] ?? null),
                V::many2oneId($row['partner_id'] ?? null),
                V::many2oneName($row['partner_id'] ?? null),
                V::many2oneId($row['company_id'] ?? null),
                V::many2oneName($row['company_id'] ?? null),
                V::many2oneName($row['currency_id'] ?? null),
                V::date($row['date_order'] ?? null),
                $linesByOrder[$id] ?? [],
                $receipts,
            );
            // One PO number in two companies: the confirmed one wins, then the first.
            if (!isset($out[$name]) || (!$out[$name]->isConfirmed() && $order->isConfirmed())) {
                $out[$name] = $order;
            }
        }

        return $out;
    }

    public function billStates(array $moveIds): array
    {
        $ids = array_values(array_unique(array_filter($moveIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $moves = $this->odoo->searchRead('account.move', [['id', 'in', $ids]], F::MOVE);
        $paymentIds = [];
        foreach ($moves as $row) {
            $paymentIds = array_merge($paymentIds, V::ids($row['matched_payment_ids'] ?? null));
        }
        $payments = [];
        $paymentIds = array_values(array_unique($paymentIds));
        if ($paymentIds !== []) {
            foreach ($this->odoo->searchRead('account.payment', [['id', 'in', $paymentIds]], F::PAYMENT) as $row) {
                $id = V::id($row['id'] ?? null);
                if ($id !== null) {
                    $payments[$id] = new Ap2OdooPaymentRef($id, V::text($row['name'] ?? null), V::date($row['date'] ?? null), V::number($row['amount'] ?? null), V::text($row['state'] ?? null));
                }
            }
        }

        $out = [];
        foreach ($moves as $row) {
            $id = V::id($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $refs = [];
            foreach (V::ids($row['matched_payment_ids'] ?? null) as $paymentId) {
                if (isset($payments[$paymentId])) {
                    $refs[] = $payments[$paymentId];
                }
            }
            $out[$id] = new Ap2OdooBillState(
                $id,
                V::text($row['name'] ?? null),
                V::text($row['ref'] ?? null),
                V::text($row['move_type'] ?? null),
                V::text($row['state'] ?? null),
                V::text($row['payment_state'] ?? null),
                V::number($row['amount_total'] ?? null),
                V::number($row['amount_residual'] ?? null),
                V::many2oneName($row['currency_id'] ?? null),
                $refs,
            );
        }

        return $out;
    }

    public function currencyRates(string $date): Ap2OdooRates
    {
        if ($this->audCompanyId === null) {
            // FINANCE_AP2_ODOO_AUD_COMPANY_ID is not set: rates are off, not wrong. AUD only, so the sync stores nothing.
            return new Ap2OdooRates($date, ['AUD' => 1.0], []);
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('The rate date is yyyy-MM-dd.');
        }
        $from = $day->modify('-' . self::RATE_WINDOW_DAYS . ' days')->format('Y-m-d');
        $rows = $this->odoo->searchRead('res.currency.rate', [
            ['currency_id.name', 'in', F::RATE_CURRENCIES],
            ['name', '<=', $date],
            ['name', '>=', $from],
            ['company_id', 'in', [$this->audCompanyId, false]],
        ], F::RATE);

        // Per currency: the latest day; on one day the company's own rate over a shared one.
        $best = [];
        foreach ($rows as $row) {
            $code = V::many2oneName($row['currency_id'] ?? null);
            $on = V::date($row['name'] ?? null);
            $rate = V::number($row['rate'] ?? null);
            if (!in_array($code, F::RATE_CURRENCIES, true) || $on === null || $rate <= 0.0) {
                continue;
            }
            $own = V::many2oneId($row['company_id'] ?? null) === $this->audCompanyId;
            $key = $on . ($own ? '1' : '0');
            if (!isset($best[$code]) || $key > $best[$code]['key']) {
                $best[$code] = ['key' => $key, 'on' => $on, 'rate' => $rate];
            }
        }
        $aud = ['AUD' => 1.0];
        $dates = [];
        foreach (F::RATE_CURRENCIES as $code) {
            if (isset($best[$code])) {
                $aud[$code] = round(1 / $best[$code]['rate'], 6);
                $dates[$code] = $best[$code]['on'];
            }
        }

        return new Ap2OdooRates($date, $aud, $dates);
    }

    public function supplierBankAccounts(array $partnerIds): array
    {
        $ids = array_values(array_unique(array_filter($partnerIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->odoo->searchRead('res.partner.bank', [['partner_id', 'in', $ids]], F::PARTNER_BANK) as $row) {
            $id = V::id($row['id'] ?? null);
            $partnerId = V::many2oneId($row['partner_id'] ?? null);
            if ($id === null || $partnerId === null) {
                continue;
            }
            $trusted = $row['allow_out_payment'] ?? null;
            $out[$partnerId][] = new Ap2OdooBankAccount(
                $id,
                $partnerId,
                // Masked here: the full number goes no further than this line.
                Ap2OdooBankAccount::mask(V::text($row['acc_number'] ?? null)),
                V::many2oneName($row['bank_id'] ?? null),
                ($row['active'] ?? true) !== false,
                is_bool($trusted) ? $trusted : null,
                is_string($row['create_date'] ?? null) ? $row['create_date'] : null,
                is_string($row['write_date'] ?? null) ? $row['write_date'] : null,
            );
        }

        return $out;
    }

    public function postBill(Ap2OdooBill $bill): Ap2OdooPostedBill
    {
        if (!$this->postingEnabled) {
            throw new Ap2OdooPostingDisabled();
        }
        $existing = $this->existing($bill);
        if ($existing !== null) {
            return $existing;
        }

        $currency = $this->odoo->searchRead('res.currency', [['name', '=', $bill->currency]], ['id', 'name']);
        $currencyId = V::id($currency[0]['id'] ?? null);
        if ($currencyId === null) {
            throw new OdooError(sprintf('Odoo has no currency %s.', $bill->currency));
        }
        $moveId = $this->writer->create('account.move', $bill->values($currencyId));
        $created = $this->odoo->searchRead('account.move', [['id', '=', $moveId]], ['id', 'name']);
        $name = V::text($created[0]['name'] ?? null);

        // Name the bill as the CSV import would, so either path finds the other's. A refusal here does not undo the
        // bill: the reference lookup above still stops a second one.
        $registered = true;
        try {
            $this->writer->create('ir.model.data', ['module' => F::XMLID_MODULE, 'name' => $bill->externalId, 'model' => 'account.move', 'res_id' => $moveId, 'noupdate' => true]);
        } catch (OdooError) {
            $registered = false;
        }

        return new Ap2OdooPostedBill($moveId, $name !== '' ? $name : '/', true, $registered);
    }

    /** The bill already in Odoo for this external id (any module), or with the same vendor, reference and type. */
    private function existing(Ap2OdooBill $bill): ?Ap2OdooPostedBill
    {
        $xmlids = $this->odoo->searchRead('ir.model.data', [['model', '=', 'account.move'], ['name', '=', $bill->externalId]], F::XMLID);
        foreach ($xmlids as $row) {
            $moveId = V::id($row['res_id'] ?? null);
            if ($moveId === null) {
                continue;
            }
            $move = $this->odoo->searchRead('account.move', [['id', '=', $moveId]], ['id', 'name', 'state']);
            if ($move !== []) {
                return new Ap2OdooPostedBill($moveId, V::text($move[0]['name'] ?? null) ?: '/', false);
            }
        }

        $same = $this->odoo->searchRead('account.move', [
            ['partner_id', '=', $bill->partnerId],
            ['ref', '=', $bill->ref],
            ['move_type', '=', $bill->moveType],
            ['company_id', '=', $bill->companyId],
            ['state', '!=', 'cancel'],
        ], ['id', 'name', 'state']);
        $moveId = V::id($same[0]['id'] ?? null);

        return $moveId === null ? null : new Ap2OdooPostedBill($moveId, V::text($same[0]['name'] ?? null) ?: '/', false, false);
    }
}
