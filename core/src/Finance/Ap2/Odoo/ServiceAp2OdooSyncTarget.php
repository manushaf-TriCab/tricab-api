<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooSyncTarget;
use TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Service\Ap2Assessor;
use TriCab\Core\Finance\Ap2\Service\Ap2PaymentService;

/**
 * {@see Ap2OdooSyncTarget} over the AP2 stores and services. Every write is absolute (so a repeated run changes nothing)
 * and leaves a line in the supplier ledger, written as the system ("Odoo sync"). A line that says the same as the last
 * line of its kind for the invoice is not written again, so a standing issue does not fill the ledger every 15 minutes.
 *
 * Payment proposals follow the bill: a proposal that is Payment approved moves to Sent to Odoo when Odoo holds a payment
 * of the same amount reconciled with the bill, and to Paid when that payment is paid. `paid` on the invoice is the
 * sync's absolute figure and {@see Ap2PaymentService::recordOdooState()} never adds to it.
 */
final class ServiceAp2OdooSyncTarget implements Ap2OdooSyncTarget
{
    /** Odoo `account.payment.state` values that are not a payment. */
    private const DEAD_PAYMENT_STATES = ['canceled', 'cancelled', 'cancel', 'rejected'];

    public function __construct(
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2PaymentStore $payments,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2Assessor $assessor,
        private readonly Ap2PaymentService $paymentService,
        private readonly Ap2Transactions $transactions,
        private readonly ApTime $time,
    ) {
    }

    public function trackedBills(): array
    {
        $out = [];
        foreach ($this->invoices->all() as $b) {
            if ($b->odooMoveId !== null && !$b->excluded && !($b->isPosted() && $b->balance() <= 0)) {
                $out[] = new Ap2OdooTrackedBill($b->id, $b->odooMoveId, $b->number, $b->currency, $b->total, $b->credit, $b->paid, $b->importState, $b->odooRef);
            }
        }

        return $out;
    }

    public function applyBillState(Ap2OdooBillUpdate $update, Ap2OdooBillState $state): void
    {
        $invoice = $this->invoices->find($update->invoiceId);
        if ($invoice === null || $invoice->odooMoveId !== $update->moveId) {
            return;
        }
        $now = $this->time->now();
        $ref = $update->odooRef !== '' ? $update->odooRef : $invoice->odooRef;
        $next = $invoice->with(importState: $update->importState, odooRef: $ref, paid: round($update->paid, 2), updatedAt: $now);
        $supplier = $this->suppliers->find($invoice->supplierId);
        $a = $this->assessor->one($next, $supplier, $this->time->today());
        $next = $next->with(stage: Ap2InvoiceRules::stage($next, $a->validation));

        $differs = $next->importState !== $invoice->importState
            || $next->odooRef !== $invoice->odooRef
            || abs($next->paid - $invoice->paid) >= 0.005
            || $next->stage !== $invoice->stage;

        $this->transactions->run(function () use ($differs, $next, $invoice, $update, $now): void {
            if ($differs) {
                $this->invoices->update($next);
            }
            if ($update->changed) {
                $detail = $invoice->number . ' · ' . $next->importState . ($next->odooRef !== '' ? ' · ' . $next->odooRef : '')
                    . ' · paid ' . $next->currency . ' ' . number_format($next->paid, 2)
                    . ($update->issues === [] ? '' : ' · ' . implode('; ', $update->issues));
                $this->noteOnce($invoice, 'Odoo bill state read', $detail, $now);
            }
        });

        $this->followPayments($next, $state);
    }

    public function billMissing(Ap2OdooTrackedBill $bill): void
    {
        $invoice = $this->invoices->find($bill->invoiceId);
        if ($invoice === null || $invoice->odooMoveId !== $bill->moveId) {
            return;
        }
        $now = $this->time->now();
        $this->transactions->run(function () use ($invoice, $bill, $now): void {
            // A draft that was deleted in Odoo failed its import: free the invoice to be posted or exported again.
            // A posted bill is only flagged: its financial fields stay locked until a person looks.
            if ($invoice->importState === 'Imported draft') {
                $this->invoices->update($invoice->with(importState: 'Import failed', odooMoveId: null, updatedAt: $now));
            }
            $this->noteOnce($invoice, 'Sync failed', $invoice->number . ' · Odoo bill ' . $bill->moveId . ' is no longer in Odoo', $now);
        });
    }

    public function awaitingMatching(): array
    {
        $out = [];
        foreach ($this->invoices->all() as $b) {
            if (in_array($b->kind, ['Goods PO', 'Service PO'], true) && $b->po !== '' && !$b->excluded && !$b->isPosted()
                && Ap2InvoiceRules::matching($b) !== 'Matched') {
                $out[] = new Ap2OdooMatchRequest($b->id, $b->po, $b->currency);
            }
        }

        return $out;
    }

    public function applyPurchaseOrder(Ap2OdooMatchFacts $facts): void
    {
        $invoice = $this->invoices->find($facts->invoiceId);
        if ($invoice === null || $invoice->excluded || $invoice->isPosted()) {
            return;
        }
        $now = $this->time->now();
        $next = $invoice;
        if ($facts->found) {
            $next = $invoice->with(
                orderedQty: $facts->orderedQty,
                receivedQty: $facts->receivedQty,
                poUnitPrice: $facts->poUnitPrice ?? $invoice->poUnitPrice,
                receiptRef: $facts->receiptRef !== '' ? $facts->receiptRef : $invoice->receiptRef,
            );
        }
        $differs = $next->orderedQty !== $invoice->orderedQty
            || $next->receivedQty !== $invoice->receivedQty
            || $next->poUnitPrice !== $invoice->poUnitPrice
            || $next->receiptRef !== $invoice->receiptRef;
        if ($differs) {
            $a = $this->assessor->one($next, $this->suppliers->find($invoice->supplierId), $this->time->today());
            $next = $next->with(stage: Ap2InvoiceRules::stage($next, $a->validation), updatedAt: $now);
        }

        $this->transactions->run(function () use ($differs, $next, $invoice, $facts, $now): void {
            if ($differs) {
                $this->invoices->update($next);
            }
            if ($differs || $facts->issues !== []) {
                $detail = $invoice->number . ' · ' . $facts->po . ($facts->found
                    ? ' · ordered ' . $next->orderedQty . ', received ' . $next->receivedQty . ($next->receiptRef !== '' ? ' (' . $next->receiptRef . ')' : '')
                    : '')
                    . ($facts->issues === [] ? '' : ' · ' . implode('; ', $facts->issues));
                $this->noteOnce($invoice, 'Odoo PO read', $detail, $now);
            }
        });
    }

    public function applyCurrencyRates(Ap2OdooRates $rates): void
    {
        $current = $this->settings->get()->fxRates;
        $merged = $current + ['AUD' => 1.0];
        foreach ($rates->audPerUnit as $code => $rate) {
            if ($code !== 'AUD' && in_array($code, Ap2Invoice::CURRENCIES, true) && $rate > 0.0) {
                $merged[$code] = $rate;
            }
        }
        if ($merged === $current) {
            return;
        }
        $this->settings->saveFxRates($merged, 0);
    }

    private function followPayments(Ap2Invoice $invoice, Ap2OdooBillState $state): void
    {
        $live = array_values(array_filter($state->payments, static fn (Ap2OdooPaymentRef $p): bool => !in_array(strtolower($p->state), self::DEAD_PAYMENT_STATES, true)));
        if ($live === []) {
            return;
        }
        $proposals = array_reverse(array_values(array_filter(
            $this->payments->all(),
            static fn (Ap2Payment $p): bool => $p->invoiceId === $invoice->id && in_array($p->status, ['Payment approved', 'Sent to Odoo'], true),
        )));
        $claimed = [];
        foreach ($proposals as $p) {
            if ($p->odooPaymentRef !== '') {
                $claimed[$p->odooPaymentRef] = true;
            }
        }
        foreach ($proposals as $p) {
            $match = null;
            foreach ($live as $odooPayment) {
                $mine = $p->odooPaymentRef !== '' && $p->odooPaymentRef === $odooPayment->name;
                $free = $p->odooPaymentRef === '' && !isset($claimed[$odooPayment->name]) && abs($odooPayment->amount - $p->amount) < 0.005;
                if ($mine || $free) {
                    $match = $odooPayment;
                    break;
                }
            }
            if ($match === null) {
                continue;
            }
            $claimed[$match->name] = true;
            try {
                if ($p->status === 'Payment approved') {
                    $this->paymentService->recordOdooState($p->id, 'Sent to Odoo', $match->name);
                }
                if (strtolower($match->state) === 'paid') {
                    $this->paymentService->recordOdooState($p->id, 'Paid', '');
                }
            } catch (Ap2Failure $failure) {
                $this->noteOnce($invoice, 'Odoo payment not recorded', $invoice->number . ' · ' . $match->name . ' · ' . $failure->getMessage(), $this->time->now());
            }
        }
    }

    /** Appends a ledger line as the system unless the newest line of that type for the invoice says the same. */
    private function noteOnce(Ap2Invoice $invoice, string $type, string $detail, \DateTimeImmutable $at): void
    {
        foreach ($this->history->forSupplier($invoice->supplierId) as $entry) {
            if ($entry->invoiceId === $invoice->id && $entry->type === $type) {
                if ($entry->detail === $detail) {
                    return;
                }
                break;
            }
        }
        $this->history->append(new Ap2HistoryEntry(bin2hex(random_bytes(16)), $invoice->supplierId, $type, $detail, new Ap2Person(0, 'Odoo sync', ''), $at, $invoice->id));
    }
}
