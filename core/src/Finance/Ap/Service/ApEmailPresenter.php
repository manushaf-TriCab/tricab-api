<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApEmail;

/**
 * An email as the source's JSON shaped it (T6.6, "the email row"), which is what the AP Mailbox page reads:
 *
 * - `received_at`, `deleted_at` and the dates are ISO 8601 UTC with `+00:00`;
 * - `deleted_by` reads `null` for none (the table stores `''`), as the source's did; the other text columns are as stored
 *   (the source stored `""` for a message with no subject or sender name);
 * - `invoice_ids` and `duplicate_invoice_ids` are on the list only: the answers of a reclassification and of a bulk action
 *   carry the plain row, as the source's did.
 */
final class ApEmailPresenter
{
    /**
     * @param list<string>|null $invoiceIds null leaves both link lists off the row
     * @param list<string> $duplicateInvoiceIds
     * @return array<string, mixed>
     */
    public static function email(ApEmail $email, ?array $invoiceIds = null, array $duplicateInvoiceIds = []): array
    {
        $row = [
            'id' => $email->id,
            'graph_message_id' => $email->graphMessageId,
            'mailbox' => $email->mailbox,
            'received_at' => ApPresenter::iso($email->receivedAt),
            'sender_name' => $email->senderName,
            'sender_email' => $email->senderEmail,
            'subject' => $email->subject,
            'body_preview' => $email->bodyPreview,
            'web_link' => $email->webLink,
            'has_attachments' => $email->hasAttachments,
            'classification' => $email->classification,
            'classification_reason' => $email->classificationReason,
            'classification_confidence' => $email->classificationConfidence,
            'review_status' => $email->reviewStatus,
            'proposed_response' => $email->proposedResponse,
            'scan_run_id' => $email->scanRunId,
            'deleted_at' => ApPresenter::iso($email->deletedAt),
            'deleted_by' => $email->deletedBy === '' ? null : $email->deletedBy,
            'created_date' => ApPresenter::iso($email->createdAt),
            'updated_date' => ApPresenter::iso($email->updatedAt),
        ];
        if ($invoiceIds !== null) {
            $row['invoice_ids'] = $invoiceIds;
            $row['duplicate_invoice_ids'] = $duplicateInvoiceIds;
        }

        return $row;
    }
}
