<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalEvent;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/**
 * Payment PROPOSALS. Tri-AP2 prepares and controls; Odoo pays (decision 4), so there is no bank step here.
 *
 *  - A proposal is allowed only when the invoice has no payment issue, the amount is within the open balance and the
 *    per-batch cash limit, the active proposals together stay within the limit (above it: CFO review required), the date
 *    is today or later (Sydney) and `paymentProposalsEnabled` is on.
 *  - Approval goes through the approvals route (kind Payment): Proposed -> Awaiting payment approval -> Payment approved
 *    or Rejected ({@see Ap2ApprovalService}).
 *  - A person can only cancel (from Proposed, Awaiting payment approval or Payment approved), which supersedes the
 *    pending approval. Sent to Odoo and Paid are set by the Odoo stream through {@see self::recordOdooState()}, which
 *    has no route.
 */
final class Ap2PaymentService
{
    public function __construct(
        private readonly Ap2PaymentStore $payments,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2ApprovalStore $approvals,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2Assessor $assessor,
        private readonly Ap2Snapshots $snapshots,
        private readonly Ap2Transactions $transactions,
        private readonly ApTime $time,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $names = [];
        foreach ($this->suppliers->all() as $s) {
            $names[$s->id] = $s->name;
        }
        $numbers = [];
        foreach ($this->invoices->all() as $b) {
            $numbers[$b->id] = $b->number;
        }

        return array_map(
            static fn (Ap2Payment $p): array => Ap2Presenter::payment($p, $names[$p->supplierId] ?? '', $numbers[$p->invoiceId] ?? ''),
            $this->payments->all(),
        );
    }

    /**
     * `POST /payments`: `{invoiceId, amount, date}`.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(Ap2Actor $actor, array $body): array
    {
        $input = new Ap2Input($body);
        $input->id('invoiceId');
        $input->money('amount');
        $input->date('date');
        $issues = $input->issues();
        foreach (['invoiceId', 'amount', 'date'] as $key) {
            if (!$input->has($key)) {
                $issues[] = $key . ' is required';
            }
        }
        $values = $input->values();
        $amount = is_float($values['amount'] ?? null) ? $values['amount'] : 0.0;
        $date = is_string($values['date'] ?? null) ? $values['date'] : '';
        if ($input->has('amount') && !in_array('amount must be a number of zero or more', $issues, true) && $amount <= 0) {
            $issues[] = 'amount must be more than zero';
        }
        if ($input->has('date') && $date === '' && !in_array('date must be a date as yyyy-mm-dd', $issues, true)) {
            $issues[] = 'date is required';
        }
        if ($date !== '' && $date < $this->time->today()) {
            $issues[] = 'date must be today or later';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The payment is invalid', Ap2Failure::details($issues));
        }

        $settings = $this->settings->get();
        if (!$settings->paymentProposalsEnabled) {
            throw new Ap2Conflict('Payment proposals are switched off', Ap2Failure::details(['Payments are made in Odoo; payment proposals are disabled in the settings']));
        }
        $invoice = $this->invoices->find(is_string($values['invoiceId'] ?? null) ? $values['invoiceId'] : '')
            ?? throw new Ap2Invalid('The invoice does not exist', Ap2Failure::details(['invoiceId does not match an invoice']));
        $supplier = $this->suppliers->find($invoice->supplierId);
        $assessment = $this->assessor->one($invoice, $supplier, $this->time->today());
        if ($assessment->paymentIssues !== []) {
            throw new Ap2Conflict('The invoice cannot be paid yet', Ap2Failure::details($assessment->paymentIssues));
        }
        if ($amount > $assessment->balance + 0.001) {
            throw new Ap2Conflict('The amount is more than the open balance', Ap2Failure::details(['amount exceeds the remaining balance of ' . number_format($assessment->balance, 2)]));
        }
        $fx = new Ap2Fx($settings->fxRates);
        $limit = $settings->batchCashLimit;
        if ($fx->toAud($amount, $invoice->currency) > $limit + 0.001) {
            throw new Ap2Conflict('The amount is over the cash limit', Ap2Failure::details(['amount exceeds the cash limit of AUD ' . number_format($limit, 2) . '; CFO review required']));
        }

        $now = $this->time->now();
        $payment = new Ap2Payment(bin2hex(random_bytes(16)), $invoice->supplierId, $invoice->id, round($amount, 2), $invoice->currency, $date, $now, $now, createdBy: $actor->person());

        $this->transactions->run(function () use ($payment, $invoice, $actor, $fx, $limit, $now): void {
            $active = 0.0;
            foreach ($this->payments->all() as $p) {
                if ($p->isActive()) {
                    $active += $fx->toAud($p->amount, $p->currency);
                }
            }
            if ($active + $fx->toAud($payment->amount, $payment->currency) > $limit + 0.001) {
                throw new Ap2Conflict('Active proposals exceed the cash limit; CFO review required', Ap2Failure::details([
                    'Active proposals AUD ' . number_format($active, 2) . ' plus this AUD ' . number_format($fx->toAud($payment->amount, $payment->currency), 2) . ' exceed the limit of AUD ' . number_format($limit, 2) . '; CFO review required',
                ]));
            }
            $this->payments->insert($payment);
            $this->record($invoice, 'Payment proposed', $invoice->number . ' · ' . $payment->currency . ' ' . number_format($payment->amount, 2) . ' · ' . $payment->date, $actor->person(), $now, $payment);
        });

        return Ap2Presenter::payment($payment, $this->suppliers->find($payment->supplierId)->name ?? '', $invoice->number);
    }

    /**
     * `POST /payments/{id}/transition`: `{to: Cancelled}`. Later states come from Odoo.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function transition(Ap2Actor $actor, string $id, array $body): array
    {
        $payment = $this->found($id);
        $input = new Ap2Input($body);
        $input->choice('to', ['Cancelled']);
        $issues = $input->issues();
        if (!$input->has('to')) {
            $issues[] = 'to is required';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('A person can only cancel a payment proposal; later states come from Odoo', Ap2Failure::details($issues));
        }
        if (!in_array($payment->status, Ap2Payment::CANCELLABLE, true)) {
            throw new Ap2Conflict('The payment cannot be cancelled', Ap2Failure::details(['A payment that is ' . $payment->status . ' cannot be cancelled here']));
        }
        $invoice = $this->invoices->find($payment->invoiceId);
        $now = $this->time->now();
        $cancelled = $payment->with(status: 'Cancelled', updatedAt: $now);

        $this->transactions->run(function () use ($payment, $cancelled, $invoice, $actor, $now): void {
            $this->payments->update($cancelled);
            foreach ($this->approvals->pendingFor('Payment', $payment->id) as $pending) {
                $this->approvals->update($pending->with(status: 'Superseded', updatedAt: $now));
                $this->approvals->addEvent(new Ap2ApprovalEvent(bin2hex(random_bytes(16)), $pending->id, 'Superseded', $actor->person(), $now, 'The payment proposal was cancelled'));
            }
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $payment->supplierId,
                'Payment cancelled',
                ($invoice->number ?? '') . ' · ' . $payment->currency . ' ' . number_format($payment->amount, 2) . ' · was ' . $payment->status,
                $actor->person(),
                $now,
                $payment->invoiceId,
                null,
                ['paymentId' => $payment->id],
            ));
        });

        return Ap2Presenter::payment($cancelled, $this->suppliers->find($cancelled->supplierId)->name ?? '', $invoice->number ?? '');
    }

    /**
     * What the Odoo stream calls (no route): Payment approved -> Sent to Odoo -> Paid. Sent to Odoo needs the payment
     * approval and the invoice approval still current and no hold. Paid never ADDS to the invoice's `paid`: the Odoo sync
     * writes `paid` as an absolute figure (total less credit less Odoo's residual) that already includes the payment, so
     * `paid` becomes the larger of what it is and the sum of the invoice's Paid proposals (this one included). Marking a
     * payment Paid after the sync, before it, or twice therefore cannot count it twice.
     *
     * @return array<string, mixed>
     */
    public function recordOdooState(string $paymentId, string $to, string $odooPaymentRef): array
    {
        $payment = $this->found($paymentId);
        $allowed = ['Payment approved' => 'Sent to Odoo', 'Sent to Odoo' => 'Paid'];
        if (($allowed[$payment->status] ?? null) !== $to) {
            throw new Ap2Conflict('Invalid payment transition', Ap2Failure::details(['A payment that is ' . $payment->status . ' cannot become ' . $to]));
        }
        $odooPaymentRef = trim($odooPaymentRef);
        if ($odooPaymentRef === '' && $to === 'Sent to Odoo') {
            throw new Ap2Invalid('The Odoo payment reference is required', Ap2Failure::details(['odooPaymentRef is required']));
        }
        $invoice = $this->invoices->find($payment->invoiceId) ?? throw new Ap2NotFound('Invoice not found');
        $supplier = $this->suppliers->find($invoice->supplierId);
        $now = $this->time->now();
        $system = new Ap2Person(0, 'Odoo sync', '');

        if ($to === 'Sent to Odoo') {
            $approval = $payment->approvalId === null ? null : $this->approvals->get($payment->approvalId);
            if ($approval === null || $approval->status !== 'Approved' || !$this->snapshots->isCurrent($approval)) {
                throw new Ap2Conflict('Payment approval is missing or stale', Ap2Failure::details(['The payment approval is missing or the invoice, amount or date changed since it']));
            }
            $a = $this->assessor->one($invoice, $supplier, $this->time->today());
            $blocking = array_values(array_filter($a->paymentIssues, static fn (string $i): bool => !in_array($i, ['Already in an active payment plan', 'No open balance'], true)));
            // "No open balance" is no objection here: the Odoo sync may already have read this payment into `paid`.
            if ($blocking !== []) {
                throw new Ap2Conflict('Payment hold / bank verification requires review', Ap2Failure::details($blocking));
            }
        }
        $otherPaid = $to === 'Paid' ? $this->paidProposals($invoice->id, $payment->id) : 0.0;
        if ($to === 'Paid' && $otherPaid + $payment->amount > round($invoice->total - $invoice->credit, 2) + 0.001) {
            throw new Ap2Conflict('Allocation exceeds the current remaining balance', Ap2Failure::details(['The payment is more than the open balance']));
        }

        $changed = $payment->with(status: $to, odooPaymentRef: $odooPaymentRef !== '' ? $odooPaymentRef : $payment->odooPaymentRef, updatedAt: $now);
        $this->transactions->run(function () use ($changed, $invoice, $supplier, $to, $now, $system, $otherPaid): void {
            $this->payments->update($changed);
            if ($to === 'Paid') {
                $paid = $invoice->with(paid: round(max($invoice->paid, $otherPaid + $changed->amount), 2));
                $a = $this->assessor->one($paid, $supplier, $this->time->today());
                $this->invoices->update($paid->with(stage: Ap2InvoiceRules::stage($paid, $a->validation, $a->approvalCurrent), updatedAt: $now));
            }
            $this->record($invoice, 'Payment state recorded', $invoice->number . ' · ' . $to . ' · ' . $changed->currency . ' ' . number_format($changed->amount, 2) . ' · from Odoo', $system, $now, $changed);
        });

        return Ap2Presenter::payment($changed, $supplier->name ?? '', $invoice->number);
    }

    /** What the invoice's other proposals that are Paid add up to. */
    private function paidProposals(string $invoiceId, string $exceptPaymentId): float
    {
        $sum = 0.0;
        foreach ($this->payments->all() as $p) {
            if ($p->invoiceId === $invoiceId && $p->id !== $exceptPaymentId && $p->status === 'Paid') {
                $sum += $p->amount;
            }
        }

        return round($sum, 2);
    }

    private function record(Ap2Invoice $invoice, string $type, string $detail, Ap2Person $actor, DateTimeImmutable $at, Ap2Payment $payment): void
    {
        $this->history->append(new Ap2HistoryEntry(bin2hex(random_bytes(16)), $invoice->supplierId, $type, $detail, $actor, $at, $invoice->id, null, ['paymentId' => $payment->id]));
    }

    private function found(string $id): Ap2Payment
    {
        $payment = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->payments->find($id) : null;
        if ($payment === null) {
            throw new Ap2NotFound('Payment not found');
        }

        return $payment;
    }
}
