<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalRef;
use TriCab\Core\Finance\Ap2\Domain\Ap2Assessment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/** Runs {@see Ap2InvoiceRules} with what it needs from the stores: the supplier's other invoices, the approval, live payments. */
final class Ap2Assessor
{
    public function __construct(
        private readonly Ap2ApprovalStore $approvals,
        private readonly Ap2InvoiceStore $invoices,
    ) {
    }

    /** The approval the invoice links to, if any. */
    public function approvalOf(Ap2Invoice $invoice): ?Ap2ApprovalRef
    {
        return $invoice->approvalId === null ? null : $this->approvals->find($invoice->approvalId);
    }

    /** One invoice, comparing it with its supplier's others. */
    public function one(Ap2Invoice $invoice, ?Ap2Supplier $supplier, string $today): Ap2Assessment
    {
        $approval = $invoice->approvalId === null ? null : $this->approvals->find($invoice->approvalId);

        return Ap2InvoiceRules::assess(
            $invoice,
            $supplier,
            $this->invoices->forSupplier($invoice->supplierId),
            $approval,
            $this->invoices->hasActivePayment($invoice->id),
            $today,
        );
    }

    /**
     * Many invoices at once (the lists, the dashboard): the approvals and the payments are read once.
     *
     * @param list<Ap2Invoice>          $invoices
     * @param array<string, Ap2Supplier> $suppliers by id
     * @return array<string, Ap2Assessment> by invoice id
     */
    public function many(array $invoices, array $suppliers, string $today): array
    {
        $bySupplier = [];
        $approvalIds = [];
        foreach ($invoices as $invoice) {
            $bySupplier[$invoice->supplierId][] = $invoice;
            if ($invoice->approvalId !== null) {
                $approvalIds[] = $invoice->approvalId;
            }
        }
        $approvals = $this->approvals->findMany(array_values(array_unique($approvalIds)));
        $active = $this->invoices->invoiceIdsWithActivePayment();

        $out = [];
        foreach ($invoices as $invoice) {
            $out[$invoice->id] = Ap2InvoiceRules::assess(
                $invoice,
                $suppliers[$invoice->supplierId] ?? null,
                $bySupplier[$invoice->supplierId] ?? [],
                $invoice->approvalId === null ? null : ($approvals[$invoice->approvalId] ?? null),
                isset($active[$invoice->id]),
                $today,
            );
        }

        return $out;
    }
}
