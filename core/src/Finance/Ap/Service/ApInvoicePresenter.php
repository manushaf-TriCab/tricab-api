<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApInvoice;

/**
 * An invoice as the source's JSON shaped it, which is what the ported pages read (T6.4.A), with the platform's changes:
 *
 * - `status` is the derived one (Overdue is never stored, Q4);
 * - there is no `attachment_url` (Q7): `attachment` is the file's name, `file_id` names the stored document or is null,
 *   `has_file` says whether there is one. No address of a file is ever returned;
 * - a text column the table stores as `''` reads `null`, as the source's did (`sender`, `vendor`, `deleted_by`, ...);
 * - `sender_name` is the display name of the sender of the source mail, `""` when unknown;
 * - money is a JSON number, dates are `yyyy-MM-dd`, timestamps ISO 8601 UTC with `+00:00`.
 */
final class ApInvoicePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function invoice(ApInvoice $row, string $today): array
    {
        return [
            'id' => $row->id,
            'created_date' => ApPresenter::iso($row->createdAt),
            'updated_date' => ApPresenter::iso($row->updatedAt),
            'email_id' => $row->emailId,
            'email_record_id' => $row->emailRecordId,
            'attachment_id' => $row->attachmentId,
            'mailbox' => $row->mailbox,
            'email_date' => ApPresenter::iso($row->emailDate),
            'sender' => self::blank($row->sender),
            'sender_name' => $row->senderName,
            'subject' => self::blank($row->subject),
            'attachment' => self::blank($row->attachment),
            'file_id' => $row->fileId,
            'has_file' => $row->fileId !== null,
            'content_hash' => $row->contentHash,
            'invoice_no' => self::blank($row->invoiceNo),
            'vendor' => self::blank($row->vendor),
            'vendor_email' => self::blank($row->vendorEmail),
            'tax_id' => self::blank($row->taxId),
            'invoice_date' => $row->invoiceDate,
            'due_date' => $row->dueDate,
            'po_number' => self::blank($row->poNumber),
            'currency' => self::blank($row->currency),
            'net_amount' => $row->netAmount,
            'tax_amount' => $row->taxAmount,
            'total_amount' => $row->totalAmount,
            'company' => self::blank($row->company),
            'account_category' => self::blank($row->accountCategory),
            'cost_centre' => self::blank($row->costCentre),
            'status' => $row->shownStatus($today),
            'notes' => $row->notes,
            'is_revised' => $row->isRevised,
            'duplicate_status' => $row->duplicateStatus,
            'duplicate_of_invoice_id' => $row->duplicateOfInvoiceId,
            'duplicate_reason' => self::blank($row->duplicateReason),
            'approval_status' => $row->approvalStatus,
            'extraction_confidence' => $row->extractionConfidence,
            'review_reason' => $row->reviewReason,
            'deleted_at' => ApPresenter::iso($row->deletedAt),
            'deleted_by' => self::blank($row->deletedBy),
            'odoo_vendor_id' => $row->odooVendorId,
            'vendor_ref' => self::blank($row->vendorRef),
            'odoo_vendor_name' => self::blank($row->odooVendorName),
            'vendor_match' => $row->vendorMatch,
            'vendor_match_note' => self::blank($row->vendorMatchNote),
        ];
    }

    private static function blank(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
