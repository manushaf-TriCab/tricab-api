<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApApprovalBatch;
use TriCab\Core\Finance\Ap\Domain\ApApprovalDecision;
use TriCab\Core\Finance\Ap\Domain\ApApprover;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;

/**
 * An approval batch as the source's `_approval_payload` shaped it, which the Approvals pages read (T6.11 P3), with the people
 * filled in: `prepared_by_user` and `decided_by_user` are built from the names recorded on the batch and the decision (the
 * source's came from an empty users table and were always null, fault R5). Ids that name a person are text.
 */
final class ApApprovalPresenter
{
    /**
     * @param list<ApInvoice> $invoices ordered by vendor, then invoice number
     * @param list<ApApprovalDecision> $decisions oldest first
     * @param list<string> $notified the request mail's recipients
     * @return array<string, mixed>
     */
    public static function batch(ApApprovalBatch $batch, array $invoices, array $decisions, array $notified, string $today): array
    {
        $decision = $decisions === [] ? null : $decisions[count($decisions) - 1];

        return [
            'id' => $batch->id,
            'prepared_by' => ApPresenter::person($batch->preparedByUserId),
            'status' => $batch->status,
            'note' => $batch->note,
            'decided_by' => ApPresenter::person($batch->decidedByUserId),
            'decided_at' => ApPresenter::iso($batch->decidedAt),
            'decision_comment' => $batch->decisionComment,
            'created_date' => ApPresenter::iso($batch->createdAt),
            'updated_date' => ApPresenter::iso($batch->updatedAt),
            'invoices' => array_map(static fn (ApInvoice $invoice): array => ApInvoicePresenter::invoice($invoice, $today), $invoices),
            'decisions' => array_map(static fn (ApApprovalDecision $d): array => [
                'id' => $d->id,
                'batch_id' => $d->batchId,
                'approver_id' => ApPresenter::person($d->approverUserId),
                'decision' => $d->decision,
                'comment' => $d->comment,
                'created_date' => ApPresenter::iso($d->createdAt),
                'approver_name' => $d->approverName,
                'approver_email' => $d->approverEmail,
            ], $decisions),
            'prepared_by_user' => $batch->preparedByUserId === null && $batch->preparedByName === '' && $batch->preparedByEmail === ''
                ? null
                : [
                    'id' => ApPresenter::person($batch->preparedByUserId),
                    'full_name' => $batch->preparedByName !== '' ? $batch->preparedByName : $batch->preparedByEmail,
                    'email' => $batch->preparedByEmail,
                ],
            'decided_by_user' => $batch->isPending() || ($batch->decidedByUserId === null && $decision === null)
                ? null
                : [
                    'id' => ApPresenter::person($batch->decidedByUserId ?? $decision?->approverUserId),
                    'full_name' => self::first($batch->decidedByName, $decision?->approverName ?? '', $decision?->approverEmail ?? ''),
                    'email' => $decision?->approverEmail ?? '',
                ],
            'notified_emails' => $notified,
        ];
    }

    /**
     * An approver as `GET /approvers` lists them.
     *
     * @return array{id: string, full_name: string, email: string}
     */
    public static function approver(ApApprover $approver): array
    {
        return [
            'id' => (string) $approver->userId,
            'full_name' => $approver->name !== '' ? $approver->name : $approver->email,
            'email' => $approver->email,
        ];
    }

    private static function first(string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
