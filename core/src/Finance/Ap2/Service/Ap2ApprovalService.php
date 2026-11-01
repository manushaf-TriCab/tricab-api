<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateInterval;
use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailDrafts;
use TriCab\Core\Finance\Ap2\Contract\Ap2NotificationStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Outbox;
use TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Approval;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalEvent;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Forbidden;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Domain\Ap2Notification;
use TriCab\Core\Finance\Ap2\Domain\Ap2OutboxMessage;
use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Module;

/**
 * Approvals (Phase 5): request, list, read and decide.
 *
 * Rules:
 *  - ONE approval per item (an invoice, an email or a payment proposal). A request for several invoices makes several
 *    approvals; a new request supersedes the item's earlier Pending ones.
 *  - The approver is an active platform user holding APPROVALS.DECIDE (and PAYMENTS.APPROVE for a payment), and is
 *    not the requester. Only that person may decide, signed in as themselves. Opening a link never approves.
 *  - The snapshot (see {@see Ap2Snapshots}) is computed here, never taken from the client, and is checked again at
 *    decision time: an item that changed since the request is a 409 and needs a fresh request.
 *  - A request expires `approvalExpiryDays` after it was made. Expiry is computed on read (a Pending approval past
 *    its `expiresAt` reads as Expired), so no job is needed and nothing can be decided late whether or not a job ran.
 *  - A rejection needs a comment.
 *  - Every write appends to the supplier's history and tells the other person in the bell; the email goes to the outbox.
 */
final class Ap2ApprovalService
{
    private const MAX_ITEMS = 100;

    public function __construct(
        private readonly Ap2ApprovalStore $approvals,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2PaymentStore $payments,
        private readonly Ap2MailDrafts $mail,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2NotificationStore $notifications,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2PersonDirectory $people,
        private readonly Ap2Outbox $outbox,
        private readonly Ap2Assessor $assessor,
        private readonly Ap2Snapshots $snapshots,
        private readonly Ap2Transactions $transactions,
        private readonly Ap2Time $time,
    ) {
    }

    /**
     * `GET /approvals?status`
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function list(array $params): array
    {
        $status = is_string($params['status'] ?? null) ? trim($params['status']) : '';
        if ($status !== '' && !in_array($status, Ap2Approval::STATUSES, true)) {
            throw new Ap2Invalid('Unknown status', Ap2Failure::details(['status must be one of ' . implode(', ', Ap2Approval::STATUSES)]));
        }
        $now = $this->time->now();
        $out = [];
        foreach ($this->approvals->all() as $approval) {
            if ($status === '' || $approval->effectiveStatus($now) === $status) {
                $out[] = $this->present($approval, $now);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->present($this->found($id), $this->time->now());
    }

    /**
     * `GET /approvals/approvers[?kind=Payment]`: who may be asked.
     *
     * @param array<string, mixed> $params
     * @return list<array{userId: int, name: string, email: string}>
     */
    public function approvers(array $params): array
    {
        $kind = is_string($params['kind'] ?? null) ? $params['kind'] : '';
        if ($kind !== '' && !in_array($kind, Ap2Approval::KINDS, true)) {
            throw new Ap2Invalid('Unknown kind', Ap2Failure::details(['kind must be one of ' . implode(', ', Ap2Approval::KINDS)]));
        }

        return array_map(static fn (Ap2Person $p): array => $p->toArray(), $this->eligibleApprovers($kind));
    }

    /**
     * `POST /approvals`: `{kind, invoiceIds[] | mailId | paymentId, approverUserId, subject?, summary?, retryOf?}`.
     * Answers one approval per item. A subject or summary given with several invoices applies to each of them.
     *
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    public function request(Ap2Actor $actor, array $body): array
    {
        $input = new Ap2Input($body);
        $input->choice('kind', Ap2Approval::KINDS);
        $input->userId('approverUserId');
        $input->text('subject', 400);
        $input->text('summary', 10000);
        $input->id('retryOf');
        $input->id('mailId');
        $input->id('paymentId');
        $issues = $input->issues();
        $values = $input->values();
        $kind = is_string($values['kind'] ?? null) ? $values['kind'] : '';
        if ($kind === '' && !$input->has('kind')) {
            $issues[] = 'kind is required';
        }
        $approverId = $values['approverUserId'] ?? null;
        if (!is_int($approverId) && !$input->has('approverUserId')) {
            $issues[] = 'approverUserId is required';
        }
        $itemIds = $this->itemIds($kind, $values, $body, $issues);
        if ($issues !== []) {
            throw new Ap2Invalid('The approval request is invalid', Ap2Failure::details($issues));
        }
        $retryOf = is_string($values['retryOf'] ?? null) ? $values['retryOf'] : null;
        if ($retryOf !== null && $this->approvals->get($retryOf) === null) {
            throw new Ap2Invalid('The approval request is invalid', Ap2Failure::details(['retryOf does not match an approval']));
        }

        $approver = $this->approver(is_int($approverId) ? $approverId : 0, $kind, $actor);
        $subject = is_string($values['subject'] ?? null) ? preg_replace('/[\r\n]+/', ' ', $values['subject']) : '';
        $summary = is_string($values['summary'] ?? null) ? $values['summary'] : '';
        $days = max(1, $this->settings->get()->approvalExpiryDays);

        return $this->transactions->run(function () use ($actor, $kind, $itemIds, $approver, $subject, $summary, $retryOf, $days): array {
            $out = [];
            foreach ($itemIds as $itemId) {
                $out[] = $this->requestOne($actor, $kind, $itemId, $approver, (string) $subject, $summary, $retryOf, $days);
            }

            return $out;
        });
    }

    /**
     * `POST /approvals/{id}/decision`: `{decision: approve|reject, comment}`.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function decide(Ap2Actor $actor, string $id, array $body): array
    {
        $approval = $this->found($id);
        $input = new Ap2Input($body);
        $input->choice('decision', ['approve', 'reject']);
        $input->text('comment', 4000);
        $issues = $input->issues();
        if (!$input->has('decision')) {
            $issues[] = 'decision is required';
        }
        $values = $input->values();
        $decision = is_string($values['decision'] ?? null) ? $values['decision'] : '';
        $comment = is_string($values['comment'] ?? null) ? $values['comment'] : '';
        if ($decision === 'reject' && $comment === '' && $issues === []) {
            $issues[] = 'Add a rejection reason';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The decision is invalid', Ap2Failure::details($issues));
        }

        if ($actor->userId !== $approval->approver->userId) {
            throw new Ap2Forbidden('Only the named approver can decide this request');
        }
        if ($actor->userId === $approval->requester->userId) {
            throw new Ap2Forbidden('You cannot decide your own request');
        }
        if ($approval->kind === 'Payment' && !$actor->can(Module::PERMISSION_AP2_PAYMENTS_APPROVE)) {
            throw new Ap2Forbidden('Approving a payment needs the payment approval permission');
        }
        $now = $this->time->now();
        $status = $approval->effectiveStatus($now);
        if ($status === 'Expired') {
            throw new Ap2Conflict('The approval has expired. Request it again.', Ap2Failure::details(['The request expired on ' . Ap2Presenter::time($approval->expiresAt ?? $now)]));
        }
        if ($status !== 'Pending') {
            throw new Ap2Conflict('Approval is no longer pending', Ap2Failure::details(['The request is ' . $status]));
        }
        if (!$this->snapshots->isCurrent($approval)) {
            throw new Ap2Conflict('Content changed. Request fresh approval.', Ap2Failure::details(['The item changed after the request was made, so this approval no longer applies']));
        }

        $approved = $decision === 'approve';
        $word = $approved ? 'Approved' : 'Rejected';

        $this->transactions->run(function () use ($approval, $actor, $now, $approved, $word, $comment): void {
            $decided = $approval->with(status: $word, decidedAt: $now, comment: $comment, updatedAt: $now);
            $this->approvals->update($decided);
            $this->approvals->addEvent(new Ap2ApprovalEvent(bin2hex(random_bytes(16)), $approval->id, $word, $actor->person(), $now, $comment));

            if ($approval->kind === 'Invoice' && $approval->invoiceId !== null) {
                $this->restage($approval->invoiceId, $now);
            }
            if ($approval->kind === 'Payment' && $approval->paymentId !== null) {
                $payment = $this->payments->find($approval->paymentId);
                if ($payment === null || $payment->status !== 'Awaiting payment approval') {
                    throw new Ap2Conflict('The payment is no longer awaiting approval', Ap2Failure::details(['The payment proposal is ' . ($payment->status ?? 'missing')]));
                }
                $this->payments->update($payment->with(
                    status: $approved ? 'Payment approved' : 'Rejected',
                    rejection: $approved ? '' : $comment,
                    updatedAt: $now,
                ));
            }

            $supplierId = $approval->supplierId ?? '';
            $this->log($supplierId, 'Approval ' . strtolower($word), $approval->subject . ($comment === '' ? '' : ' · ' . $comment), $actor->person(), $now, $approval, ['approvalId' => $approval->id]);
            $this->notifications->add(new Ap2Notification(
                bin2hex(random_bytes(16)),
                $approval->requester->userId,
                $approval->subject . ' · ' . $word,
                $actor->name . ' ' . strtolower($word) . ' this request.' . ($comment === '' ? '' : ' ' . $comment),
                $now,
                false,
                $this->link($approval->id),
            ));
            $this->outbox->queue(new Ap2OutboxMessage(
                Ap2OutboxMessage::DECISION_NOTICE,
                $supplierId,
                $actor->person(),
                $approval->requester->email,
                $approval->subject . ' | ' . $word,
                $approval->summary . ($comment === '' ? '' : "\n" . $comment),
                '',
                $approval->invoiceId,
                $approval->mailId,
                $approval->id,
            ));
        });

        return $this->present($this->found($id), $now);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $body
     * @param list<string>         $issues
     * @return list<string>
     */
    private function itemIds(string $kind, array $values, array $body, array &$issues): array
    {
        if ($kind === 'Invoice') {
            $raw = $body['invoiceIds'] ?? null;
            if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
                $issues[] = 'invoiceIds must be a list of invoice ids';

                return [];
            }
            $ids = [];
            foreach ($raw as $one) {
                if (!is_string($one) || preg_match('/^[0-9a-f]{32}$/', $one) !== 1) {
                    $issues[] = 'invoiceIds must be a list of invoice ids';

                    return [];
                }
                $ids[$one] = $one;
            }
            if (count($ids) > self::MAX_ITEMS) {
                $issues[] = 'invoiceIds may hold at most ' . self::MAX_ITEMS . ' invoices';

                return [];
            }

            return array_values($ids);
        }
        $key = $kind === 'Email' ? 'mailId' : 'paymentId';
        if ($kind !== '' && !isset($values[$key]) && !in_array($key . ' must be an id', $issues, true)) {
            $issues[] = $key . ' is required for a' . ($kind === 'Email' ? 'n ' : ' ') . strtolower($kind) . ' approval';
        }

        return is_string($values[$key] ?? null) ? [$values[$key]] : [];
    }

    private function approver(int $userId, string $kind, Ap2Actor $actor): Ap2Person
    {
        if ($userId === $actor->userId) {
            throw new Ap2Conflict('You cannot ask yourself to approve', Ap2Failure::details(['approverUserId must be someone other than the requester']));
        }
        foreach ($this->eligibleApprovers($kind) as $person) {
            if ($person->userId === $userId) {
                return $person;
            }
        }
        throw new Ap2Invalid('The approver cannot approve', Ap2Failure::details(['approverUserId is not an active user holding the approval permission' . ($kind === 'Payment' ? ' and the payment approval permission' : '')]));
    }

    /** @return list<Ap2Person> */
    private function eligibleApprovers(string $kind): array
    {
        $people = $this->people->holding(Module::PERMISSION_AP2_APPROVALS_DECIDE);
        if ($kind !== 'Payment') {
            return $people;
        }
        $payers = [];
        foreach ($this->people->holding(Module::PERMISSION_AP2_PAYMENTS_APPROVE) as $p) {
            $payers[$p->userId] = true;
        }

        return array_values(array_filter($people, static fn (Ap2Person $p): bool => isset($payers[$p->userId])));
    }

    /** @return array<string, mixed> */
    private function requestOne(Ap2Actor $actor, string $kind, string $itemId, Ap2Person $approver, string $subject, string $summary, ?string $retryOf, int $days): array
    {
        $now = $this->time->now();
        $invoice = null;
        $payment = null;
        $mail = null;
        $label = '';
        $defaultSummary = '';
        if ($kind === 'Invoice') {
            $invoice = $this->invoices->find($itemId) ?? throw new Ap2NotFound('Invoice not found');
            if ($invoice->excluded) {
                throw new Ap2Conflict('An excluded invoice cannot be approved', Ap2Failure::details([$invoice->number . ' is an excluded duplicate']));
            }
            $supplier = $this->suppliers->find($invoice->supplierId);
            $a = $this->assessor->one($invoice, $supplier, $this->time->today());
            $label = $invoice->number;
            $defaultSummary = $invoice->number . ': ' . $this->cash($invoice->total, $invoice->currency) . ' gross less ' . $this->cash($invoice->credit, $invoice->currency)
                . ' credits. ' . $invoice->kind . '; ' . $a->matching . '; entity ' . $invoice->entity . '; GL ' . $invoice->gl . ', tax ' . $invoice->taxCode . ".\n"
                . 'Outstanding checks: ' . ($a->validation === [] ? 'None' : implode('; ', $a->validation)) . '. Approval does not clear unresolved checks or authorise payment.';
            $supplierId = $invoice->supplierId;
            $snapshot = Ap2InvoiceRules::fingerprint($invoice, $supplier);
        } elseif ($kind === 'Payment') {
            $payment = $this->payments->find($itemId) ?? throw new Ap2NotFound('Payment not found');
            if (!in_array($payment->status, ['Proposed', 'Awaiting payment approval'], true)) {
                throw new Ap2Conflict('The payment cannot be sent for approval', Ap2Failure::details(['The payment proposal is ' . $payment->status]));
            }
            $invoice = $this->invoices->find($payment->invoiceId) ?? throw new Ap2NotFound('Invoice not found');
            $supplier = $this->suppliers->find($invoice->supplierId);
            $label = $invoice->number;
            $defaultSummary = $this->cash($payment->amount, $payment->currency) . ' proposed for ' . $invoice->number . ' on ' . $payment->date . '. Posted in Odoo: '
                . ($invoice->isPosted() ? 'yes' : 'no') . '. Bank independently verified: ' . ($supplier !== null && $supplier->bankVerified && !$supplier->bankChange ? 'yes' : 'no') . '.';
            $supplierId = $payment->supplierId;
            $snapshot = $this->snapshots->payment($payment, $invoice);
        } else {
            $mail = $this->mail->find($itemId) ?? throw new Ap2NotFound('Email not found');
            if ($mail->supplierId === null) {
                throw new Ap2Invalid('The email has no supplier', Ap2Failure::details(['Link the email to a supplier before asking for approval']));
            }
            $label = $mail->subject;
            $defaultSummary = $mail->subject . "\nProposed response: " . $mail->draft;
            $supplierId = $mail->supplierId;
            $snapshot = $mail->snapshot();
        }

        $subject = $subject !== '' ? $subject : $kind . ' approval · ' . $label;
        $summary = $summary !== '' ? $summary : $defaultSummary;

        foreach ($this->approvals->pendingFor($kind, $itemId) as $earlier) {
            $this->approvals->update($earlier->with(status: 'Superseded', updatedAt: $now));
            $this->approvals->addEvent(new Ap2ApprovalEvent(bin2hex(random_bytes(16)), $earlier->id, 'Superseded', $actor->person(), $now, 'Superseded by a new request'));
        }

        $approval = new Ap2Approval(
            bin2hex(random_bytes(16)),
            $kind,
            $actor->person(),
            $approver,
            $now,
            $now,
            $invoice !== null && $kind === 'Invoice' ? $invoice->id : null,
            $mail?->id,
            $payment?->id,
            $supplierId,
            $retryOf,
            $subject,
            $summary,
            $snapshot,
            'Pending',
            null,
            '',
            $now->add(new DateInterval('P' . $days . 'D')),
        );
        $this->approvals->insert($approval);
        $this->approvals->addEvent(new Ap2ApprovalEvent(bin2hex(random_bytes(16)), $approval->id, 'Requested', $actor->person(), $now));

        if ($kind === 'Invoice' && $invoice !== null) {
            // Asking for approval makes it required (the prototype's request); a decision never moves the invoice state.
            $this->invoices->update($invoice->with(approvalId: $approval->id, approvalRequired: true, updatedAt: $now));
            $this->restage($invoice->id, $now);
        } elseif ($kind === 'Payment' && $payment !== null) {
            $this->payments->update($payment->with(approvalId: $approval->id, status: 'Awaiting payment approval', updatedAt: $now));
        } elseif ($mail !== null) {
            $this->mail->linkApproval($mail->id, $approval->id);
        }

        $this->log($supplierId, 'Approval requested', $subject . ' → ' . $approver->name, $actor->person(), $now, $approval, ['approvalId' => $approval->id, 'approverUserId' => $approver->userId]);
        $this->notifications->add(new Ap2Notification(
            bin2hex(random_bytes(16)),
            $approver->userId,
            'Approval requested · ' . $subject,
            $actor->name . ' asked you to review this.',
            $now,
            false,
            $this->link($approval->id),
        ));
        $this->outbox->queue(new Ap2OutboxMessage(Ap2OutboxMessage::APPROVAL_REQUEST, $supplierId, $actor->person(), $approver->email, $subject, $summary, '', $approval->invoiceId, $approval->mailId, $approval->id));

        return $this->present($approval, $now);
    }

    /** Re-derives an invoice's stage after its approval changed (the stage no longer depends on it; kept as a safety net). */
    private function restage(string $invoiceId, DateTimeImmutable $now): void
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            return;
        }
        $a = $this->assessor->one($invoice, $this->suppliers->find($invoice->supplierId), $this->time->today());
        $stage = Ap2InvoiceRules::stage($invoice, $a->validation);
        if ($stage !== $invoice->stage) {
            $this->invoices->update($invoice->with(stage: $stage, updatedAt: $now));
        }
    }

    /** @param array<string, mixed> $extra */
    private function log(string $supplierId, string $type, string $detail, Ap2Person $actor, DateTimeImmutable $at, Ap2Approval $approval, array $extra): void
    {
        if ($supplierId === '') {
            return;
        }
        $this->history->append(new Ap2HistoryEntry(bin2hex(random_bytes(16)), $supplierId, $type, $detail, $actor, $at, $approval->invoiceId, $approval->mailId, $extra));
    }

    private function link(string $approvalId): string
    {
        return '/finance/ap/approvals/' . $approvalId;
    }

    private function cash(float $amount, string $currency): string
    {
        return $currency . ' ' . number_format($amount, 2);
    }

    /** @return array<string, mixed> */
    private function present(Ap2Approval $approval, DateTimeImmutable $now): array
    {
        return Ap2Presenter::approval($approval, $now, $this->snapshots->isCurrent($approval), $this->approvals->events($approval->id));
    }

    private function found(string $id): Ap2Approval
    {
        $approval = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->approvals->get($id) : null;
        if ($approval === null) {
            throw new Ap2NotFound('Approval not found');
        }

        return $approval;
    }
}
