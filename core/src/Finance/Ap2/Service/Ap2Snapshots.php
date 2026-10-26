<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailDrafts;
use TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Approval;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;

/**
 * What an approval is bound to, computed on the server from the item as it is NOW (the prototype's `approvalSnapshot`):
 *  - Invoice: the invoice fingerprint (27 values, incl. the supplier's bank flags);
 *  - Payment: the invoice fingerprint, the amount and the date;
 *  - Email: the draft, the supplier, the reply-to, the CC and the attachments.
 * Each is a SHA-256, so it fits the column. An approval is decided only while its stored snapshot equals this.
 */
final class Ap2Snapshots
{
    public function __construct(
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2PaymentStore $payments,
        private readonly Ap2MailDrafts $mail,
    ) {
    }

    public function invoice(Ap2Invoice $invoice): string
    {
        return Ap2InvoiceRules::fingerprint($invoice, $this->suppliers->find($invoice->supplierId));
    }

    public function payment(Ap2Payment $payment, Ap2Invoice $invoice): string
    {
        return hash('sha256', json_encode([$this->invoice($invoice), number_format($payment->amount, 2, '.', ''), $payment->date], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** The snapshot the approval's item has now; null when the item is gone. */
    public function current(Ap2Approval $approval): ?string
    {
        switch ($approval->kind) {
            case 'Invoice':
                $invoice = $approval->invoiceId === null ? null : $this->invoices->find($approval->invoiceId);

                return $invoice === null ? null : $this->invoice($invoice);
            case 'Payment':
                $payment = $approval->paymentId === null ? null : $this->payments->find($approval->paymentId);
                $invoice = $payment === null ? null : $this->invoices->find($payment->invoiceId);

                return $payment === null || $invoice === null ? null : $this->payment($payment, $invoice);
            default:
                $mail = $approval->mailId === null ? null : $this->mail->find($approval->mailId);

                return $mail?->snapshot();
        }
    }

    public function isCurrent(Ap2Approval $approval): bool
    {
        $now = $this->current($approval);

        return $now !== null && $approval->snapshot !== null && hash_equals($approval->snapshot, $now);
    }
}
