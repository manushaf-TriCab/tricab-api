<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceQuery;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvoice;

/**
 * The vendor routes (`app.py` routes 25 to 28) around {@see ApVendorService}: they find the invoice, apply the person's
 * mailbox scope (out of scope is a 404 `Not found`, the same answer as a missing id), record who chose and write the
 * `vendor_match` audit event in the same transaction as the change. The matching rules themselves are the vendor service's.
 *
 * Every refusal is an {@see \TriCab\Core\Finance\Ap\Domain\ApFailure}: a body that cannot be used is a 422, and an empty
 * vendor list is a 503 `The Odoo vendor list is unavailable; try again shortly`.
 */
final class ApInvoiceVendorService
{
    /** The audit action `PUT /invoices/{id}/vendor-match` writes (`vendor_match`, T6.4 B6). */
    public const AUDIT_ACTION = 'vendor_match';

    /** The most invoices a run looks at when no ids narrow it (the source's `limit=100000`). */
    private const RUN_LIMIT = 100000;

    public function __construct(
        private readonly ApVendorService $vendors,
        private readonly ApInvoiceStore $invoices,
        private readonly ApAuditStore $audit,
        private readonly ApTime $time,
    ) {
    }

    /**
     * `GET /vendors`: `{synced_at, items}`, an item being `odoo_id, name, ref, email, vat, company_name, active, invoice_count`.
     *
     * @return array{items: list<array<string, mixed>>, synced_at: string|null}
     */
    public function vendors(): array
    {
        return $this->vendors->vendors();
    }

    /**
     * `GET /invoices/{id}/vendor-suggestions`: `{suggestions, buyer_like}`. The invoice is read with the sender's display
     * name (from the scanned email), as the source loaded it.
     *
     * @return array{suggestions: list<array<string, mixed>>, buyer_like: bool}
     */
    public function suggestions(ApActor $actor, string $id): array
    {
        return $this->vendors->suggestions(ApVendorInvoice::fromInvoice($this->visible($actor, $id)));
    }

    /**
     * `PUT /invoices/{id}/vendor-match`: `{odoo_vendor_id}` picks the vendor, `{status: "new"}` marks it as not in Odoo yet,
     * `{status: "auto"}` hands it back to automatic matching. Writes the five vendor columns and who chose
     * (`vendor_match_by_user_id`), and the `vendor_match` audit event `{vendor_match, odoo_vendor_id, vendor_ref}`; answers
     * the updated invoice.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function setMatch(ApActor $actor, string $id, array $body): array
    {
        $invoice = $this->visible($actor, $id);
        $who = $actor->email !== '' ? $actor->email : (string) $actor->userId;
        $now = $this->time->now();

        $this->invoices->transactional(function () use ($actor, $invoice, $body, $who, $now): void {
            $match = $this->vendors->setMatch(ApVendorInvoice::fromInvoice($invoice), $body, $who, $actor->userId);
            $this->audit->record(new ApAuditEvent(
                self::AUDIT_ACTION,
                ApAuditEvent::ENTITY_INVOICE,
                $invoice->id,
                [
                    'vendor_match' => $match->vendorMatch,
                    'odoo_vendor_id' => $match->odooVendorId,
                    'vendor_ref' => $match->vendorRef,
                ],
                $now,
                $actor->userId,
                $actor->name,
            ));
        });

        return ApInvoicePresenter::invoice($this->invoices->find($id) ?? throw new ApNotFound(ApInvoiceService::NOT_FOUND), $this->time->today());
    }

    /**
     * `POST /vendor-matching/run`: matches every live invoice the person may see that nobody has decided on. `invoice_ids`
     * narrows it; absent or empty is all. Answers `{checked, matched, unmatched}`.
     *
     * @param array<string, mixed> $body
     * @return array{checked: int, matched: int, unmatched: int}
     */
    public function run(ApActor $actor, array $body): array
    {
        $given = $body['invoice_ids'] ?? [];
        if ($given === null || $given === false || $given === '') {
            $given = [];
        }
        if (!is_array($given)) {
            throw new ApInvalid('invoice_ids must be a list');
        }
        $ids = [];
        foreach ($given as $value) {
            if (is_string($value) || is_int($value)) {
                $ids[] = (string) $value;
            }
        }

        if ($ids === []) {
            $rows = $this->invoices->page(new ApInvoiceQuery(
                false,
                null,
                null,
                null,
                null,
                $actor->mailboxScope->mailboxes(),
                ApInvoiceQuery::DEFAULT_SORT,
                true,
                self::RUN_LIMIT,
                $this->time->today(),
            ));
        } else {
            $rows = array_values(array_filter(
                $this->invoices->findMany(array_values(array_unique($ids))),
                static fn (ApInvoice $invoice): bool => !$invoice->isBinned() && $actor->mailboxScope->allowsRecord($invoice->mailbox),
            ));
        }

        return $this->vendors->run(
            array_map(static fn (ApInvoice $invoice): ApVendorInvoice => ApVendorInvoice::fromInvoice($invoice), $rows),
            $ids,
        );
    }

    /** The invoice, or `Not found` when it is missing or out of the person's scope. */
    private function visible(ApActor $actor, string $id): ApInvoice
    {
        $invoice = $this->invoices->find($id) ?? throw new ApNotFound(ApInvoiceService::NOT_FOUND);
        $actor->mailboxScope->requireRecord($invoice->mailbox, ApInvoiceService::NOT_FOUND);

        return $invoice;
    }
}
