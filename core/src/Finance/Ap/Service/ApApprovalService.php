<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use Closure;
use TriCab\Core\Finance\Ap\Contract\ApApprovalStore;
use TriCab\Core\Finance\Ap\Contract\ApApproverDirectory;
use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApApprovalBatch;
use TriCab\Core\Finance\Ap\Domain\ApApprovalDecision;
use TriCab\Core\Finance\Ap\Domain\ApApprover;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApMailboxScope;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;
use TriCab\Core\Finance\Module;

/**
 * Approvals (`app.py` routes 57 to 62, T6.11): a Preparer asks the Approvers to sign off a set of invoices; any one Approver
 * decides, the first decision wins, and the Preparer is told. Approval is informational (question Q10): it changes the
 * invoices' `approval_status` only, never their `status`, and it never blocks the D365 export.
 *
 * - People are platform users by id (question Q10): the Approvers come from {@see ApApproverDirectory}, and the batch and the
 *   decision record the name and address of whoever prepared and decided, so the pages can show them.
 * - There is no eligibility rule on an invoice (source, fault R21 kept: approval is informational): a binned, `Completed`,
 *   `Exported` or already `Pending approval` invoice is accepted, and an invoice may be in several batches. Each must exist
 *   and be in the person's mailbox scope; out of scope reads as "unavailable", like a missing one.
 * - Mail goes through {@see ApMailService::deliver()} (the grant check and an idempotency key on every send). While
 *   `FINANCE_AP_SENDING_ENABLED` is off nothing is mailed, the batch is still created and the decision still recorded, and the
 *   answer says so in `warning` / `notification_warning` (decision D80). A mail that fails for any reason is the same: the work
 *   stands and the answer carries the reason. The mail is sent from the configured AP mailbox. It is checked against the
 *   mailbox registry's send grant but not against the sender's own mailbox scope: the mailbox is the system's, not a mailbox
 *   the person browses.
 * - The first decision wins in one conditional update; a second answers a 409 carrying the batch. One person may prepare and
 *   decide the same batch (decision A11, as the source): record the risk.
 * - The decision route applies the mailbox scope (fault R7, the source's did not): a batch with an invoice out of scope is a 404.
 */
final class ApApprovalService
{
    public const NOT_FOUND = 'Not found';
    public const PREPARER_REQUIRED = 'Preparer permission required';
    public const APPROVER_REQUIRED = 'Approver permission required';
    public const SELECT_ONE = 'Select at least one invoice';
    public const UNAVAILABLE_INVOICES = 'One or more invoices are unavailable';
    public const RECIPIENTS_NOT_LIST = 'Recipients must be a list of email addresses';
    public const NO_APPROVERS = 'No active Approvers are configured. Assign the Approver role to someone, or enter an address to notify.';
    public const BAD_DECISION = 'decision must be approve or reject';
    public const APPROVERS_UNREADABLE = 'Approvers could not be read from TriCab Finance';
    public const MAIL_OFF = 'Approval mail is switched off for Tri-AP, so no one was notified.';
    public const NO_PREPARER_EMAIL = 'The decision was recorded, but the original Preparer has no email address.';

    public const APPROVAL_REQUESTED = 'approval_requested';
    public const APPROVAL_APPROVED = 'approval_approved';
    public const APPROVAL_REJECTED = 'approval_rejected';

    /** The most batches the list shows, newest first. */
    public const LIST_LIMIT = 200;
    public const MAX_RECIPIENTS = 20;

    private const SEND_FAILED_PREFIX = 'Failed to send email: ';

    /** @var Closure(): string */
    private readonly Closure $newId;

    /**
     * @param string $siteOrigin the public origin of the web app (`https://www.tricab.com`), for the link in the mail
     * @param (callable(): string)|null $newId new 32-hex ids
     */
    public function __construct(
        private readonly ApApprovalStore $approvals,
        private readonly ApInvoiceStore $invoices,
        private readonly ApApproverDirectory $directory,
        private readonly ApAuditStore $audit,
        private readonly ApMailService $mail,
        private readonly ApTime $time,
        private readonly string $siteOrigin,
        ?callable $newId = null,
    ) {
        $this->newId = Closure::fromCallable($newId ?? static fn (): string => bin2hex(random_bytes(16)));
    }

    // ---- the routes ------------------------------------------------------------------------

    /**
     * `GET /approvers`: the people who may complete a request, each once.
     *
     * @return list<array{id: string, full_name: string, email: string}>
     */
    public function approvers(): array
    {
        return array_map(ApApprovalPresenter::approver(...), $this->directory->approvers());
    }

    /**
     * `GET /approval-batches`: the newest 200 batches in whose invoices the person may see every one (a batch whose invoices
     * were all deleted is shown to everyone), each in the batch shape. A fixed number of queries whatever the length.
     *
     * @return list<array<string, mixed>>
     */
    public function list(ApActor $actor): array
    {
        $batches = $this->approvals->recent(self::LIST_LIMIT, $actor->mailboxScope->mailboxes());

        return array_map(fn (array $b): array => $this->shape($b), $this->bundles($batches));
    }

    /**
     * `GET /approval-batches/{id}`.
     *
     * @return array<string, mixed>
     * @throws ApNotFound no such batch, or an invoice of it is out of the person's scope
     */
    public function get(ApActor $actor, string $id): array
    {
        return $this->shape($this->visible($actor, $id));
    }

    /**
     * `POST /approval-batches`: asks the Approvers (or the addresses typed in) to sign off the invoices.
     *
     * @param array<string, mixed> $body `{invoice_ids, note?, recipients?}`
     * @return array{batch: array<string, mixed>, warning: string|null}
     */
    public function request(ApActor $actor, array $body): array
    {
        if (!$actor->can(Module::PERMISSION_AP_APPROVALS_PREPARE)) {
            throw new ApForbidden(self::PREPARER_REQUIRED);
        }
        $sent = $body['invoice_ids'] ?? null;
        if (!is_array($sent) || !array_is_list($sent) || $sent === []) {
            throw new ApInvalid(self::SELECT_ONE);
        }
        $ids = $this->distinctIds($sent);
        $found = $this->invoices->findMany($ids);
        foreach ($ids as $id) {
            $invoice = $found[$id] ?? null;
            if ($invoice === null || !$actor->mailboxScope->allowsRecord($invoice->mailbox)) {
                throw new ApInvalid(self::UNAVAILABLE_INVOICES);
            }
        }

        $approvers = $this->directory->approvers();
        $recipients = self::recipients($body['recipients'] ?? null, $approvers);
        if ($recipients === []) {
            throw new ApInvalid(self::NO_APPROVERS);
        }

        $now = $this->time->now();
        $note = self::text($body['note'] ?? null);
        $batch = new ApApprovalBatch(
            ($this->newId)(),
            $actor->userId,
            $this->name($actor),
            strtolower(trim($actor->email)),
            ApApprovalBatch::PENDING,
            $note,
            $now,
            $now,
        );
        $items = [];
        foreach ($ids as $id) {
            $items[($this->newId)()] = $id;
        }
        $this->approvals->transactional(function () use ($batch, $items, $ids, $now): void {
            $this->approvals->add($batch, $items, $now);
            $this->invoices->setApprovalStatus($ids, ApInvoice::APPROVAL_PENDING, $now);
        });

        $warnings = [];
        if (!$this->mail->sendingEnabled()) {
            $warnings[] = self::MAIL_OFF;
        } else {
            $approverEmails = array_map(static fn (ApApprover $a): string => $a->email, $approvers);
            $outsiders = array_values(array_filter($recipients, static fn (string $a): bool => !in_array($a, $approverEmails, true)));
            if (array_intersect($approverEmails, $recipients) === []) {
                $warnings[] = 'Nobody notified holds Approver access in TriCab Finance, so no one on this list can complete the request.';
            } elseif ($outsiders !== []) {
                $warnings[] = implode(', ', $outsiders) . ' will receive the email but cannot complete the request — they are not Approvers in TriCab Finance.';
            }
            $failed = $this->send(
                $actor,
                $batch->id,
                'request',
                $recipients,
                ApApprovalMail::requestSubject(count($ids)),
                ApApprovalMail::request(
                    $batch->preparedByName !== '' ? $batch->preparedByName : $batch->preparedByEmail,
                    $note,
                    array_values(array_filter(array_map(static fn (string $id): ?ApInvoice => $found[$id] ?? null, $ids))),
                    ApApprovalMail::url($this->siteOrigin, $batch->id),
                ),
            );
            if ($failed !== null) {
                $warnings[] = 'Approval was created, but notification email failed: ' . $failed;
            }
        }
        $warning = $warnings === [] ? null : implode(' ', $warnings);

        $this->record($actor, self::APPROVAL_REQUESTED, $batch->id, [
            'invoice_ids' => $sent,
            'notified' => $recipients,
            'notification_warning' => $warning,
        ]);
        $created = $this->approvals->find($batch->id) ?? $batch;

        return ['batch' => $this->shape($this->bundles([$created])[0]), 'warning' => $warning];
    }

    /**
     * `POST /approval-batches/{id}/decision`: the first decision wins. Answers the decided batch with `notification_warning`.
     *
     * @param array<string, mixed> $body `{decision: approve|reject, comment?}`
     * @return array<string, mixed>
     * @throws ApConflict the batch is already decided; `extra.batch` is the batch as it stands
     */
    public function decide(ApActor $actor, string $id, array $body): array
    {
        if (!$actor->can(Module::PERMISSION_AP_APPROVALS_DECIDE)) {
            throw new ApForbidden(self::APPROVER_REQUIRED);
        }
        $word = $body['decision'] ?? null;
        if ($word !== 'approve' && $word !== 'reject') {
            throw new ApInvalid(self::BAD_DECISION);
        }
        $this->visible($actor, $id);
        $status = $word === 'approve' ? ApApprovalBatch::APPROVED : ApApprovalBatch::REJECTED;
        $comment = self::text($body['comment'] ?? null);
        $name = $this->name($actor);
        $email = strtolower(trim($actor->email));
        $now = $this->time->now();

        $won = $this->approvals->transactional(function () use ($id, $status, $actor, $name, $email, $comment, $now): bool {
            if (!$this->approvals->decide($id, $status, $actor->userId, $name, $comment, $now)) {
                return false;
            }
            $this->approvals->addDecision(new ApApprovalDecision(($this->newId)(), $id, $actor->userId, $name, $email, $status, $comment, $now));
            $ids = $this->approvals->invoiceIdsOf([$id])[$id] ?? [];
            $this->invoices->setApprovalStatus($ids, $status, $now);

            return true;
        });
        if (!$won) {
            $current = $this->approvals->find($id) ?? throw new ApNotFound(self::NOT_FOUND);

            throw new ApConflict('This request was already ' . strtolower($current->status), ['batch' => $this->shape($this->bundles([$current])[0])]);
        }

        $decided = $this->approvals->find($id) ?? throw new ApNotFound(self::NOT_FOUND);
        $bundle = $this->bundles([$decided])[0];
        $warning = $this->tellPreparer($actor, $bundle['batch'], $bundle['invoices'], $name !== '' ? $name : $email);

        $this->record($actor, $word === 'approve' ? self::APPROVAL_APPROVED : self::APPROVAL_REJECTED, $id, [
            'approver_name' => $actor->name,
            'approver_email' => $actor->email,
            'comment' => is_string($body['comment'] ?? null) ? $body['comment'] : null,
            'preparer_email' => $decided->preparedByEmail !== '' ? $decided->preparedByEmail : null,
            'notification_warning' => $warning,
        ]);

        return $this->shape($bundle) + ['notification_warning' => $warning];
    }

    // ---- the mail ----------------------------------------------------------------------------

    /**
     * The outcome mail to the Preparer: null when it went out (or had gone out already), else the warning.
     *
     * @param list<ApInvoice> $invoices
     */
    private function tellPreparer(ApActor $actor, ApApprovalBatch $batch, array $invoices, string $approver): ?string
    {
        $preparer = strtolower(trim($batch->preparedByEmail));
        if ($preparer === '') {
            return self::NO_PREPARER_EMAIL;
        }
        if (!$this->mail->sendingEnabled()) {
            return self::MAIL_OFF;
        }
        $failed = $this->send(
            $actor,
            $batch->id,
            'outcome',
            [$preparer],
            ApApprovalMail::outcomeSubject($batch->status, count($invoices)),
            ApApprovalMail::outcome($batch->status, $approver, $batch->decisionComment ?? '', $invoices, ApApprovalMail::url($this->siteOrigin, $batch->id)),
        );

        return $failed === null ? null : 'The decision was recorded, but the Preparer notification failed: ' . $failed;
    }

    /**
     * Sends one approval mail from the configured AP mailbox, recorded against the batch under the key
     * `ap-approval:<batch>:<kind>`, so the same mail is never sent twice. Returns null when it went out or had gone already,
     * else the reason it did not: a failure never undoes the batch or the decision.
     *
     * @param list<string> $to
     */
    private function send(ApActor $actor, string $batchId, string $kind, array $to, string $subject, string $html): ?string
    {
        // The grant is the registry's; the mailbox is the system's, so the sender's own mailbox scope does not apply.
        $sender = new ApActor($actor->userId, $actor->name, $actor->email, $actor->permissions, ApMailboxScope::unrestricted(), $actor->isAdmin);
        try {
            $this->mail->deliver(
                $sender,
                ApOutboundTarget::approvalBatch($batchId),
                new ApOutboundMail($this->mail->defaultMailbox(), $to, $subject, $html, $html),
                'ap-approval:' . $batchId . ':' . $kind,
            );
        } catch (ApFailure $failure) {
            $reason = $failure->getMessage();

            return str_starts_with($reason, self::SEND_FAILED_PREFIX) ? substr($reason, strlen(self::SEND_FAILED_PREFIX)) : $reason;
        }

        return null;
    }

    // ---- reading -----------------------------------------------------------------------------

    /**
     * One batch the person may see, loaded.
     *
     * @return array{batch: ApApprovalBatch, invoices: list<ApInvoice>, decisions: list<ApApprovalDecision>, notified: list<string>}
     * @throws ApNotFound
     */
    private function visible(ApActor $actor, string $id): array
    {
        $batch = $this->approvals->find($id) ?? throw new ApNotFound(self::NOT_FOUND);
        $bundle = $this->bundles([$batch])[0];
        foreach ($bundle['invoices'] as $invoice) {
            $actor->mailboxScope->requireRecord($invoice->mailbox, self::NOT_FOUND);
        }

        return $bundle;
    }

    /**
     * The batches with their invoices (by vendor, then invoice number), decisions and recipients, in four queries however many.
     *
     * @param list<ApApprovalBatch> $batches
     * @return list<array{batch: ApApprovalBatch, invoices: list<ApInvoice>, decisions: list<ApApprovalDecision>, notified: list<string>}>
     */
    private function bundles(array $batches): array
    {
        if ($batches === []) {
            return [];
        }
        $batchIds = array_map(static fn (ApApprovalBatch $b): string => $b->id, $batches);
        $itemIds = $this->approvals->invoiceIdsOf($batchIds);
        $decisions = $this->approvals->decisionsOf($batchIds);
        $notified = $this->approvals->notifiedTo($batchIds);
        $all = [];
        foreach ($itemIds as $ids) {
            foreach ($ids as $id) {
                $all[$id] = $id;
            }
        }
        $rows = $this->invoices->findMany(array_values($all));

        $out = [];
        foreach ($batches as $batch) {
            $invoices = [];
            foreach ($itemIds[$batch->id] ?? [] as $id) {
                if (isset($rows[$id])) {
                    $invoices[] = $rows[$id];
                }
            }
            usort($invoices, static fn (ApInvoice $a, ApInvoice $b): int => [mb_strtolower($a->vendor), mb_strtolower($a->invoiceNo), $a->id] <=> [mb_strtolower($b->vendor), mb_strtolower($b->invoiceNo), $b->id]);
            $out[] = ['batch' => $batch, 'invoices' => $invoices, 'decisions' => $decisions[$batch->id] ?? [], 'notified' => $notified[$batch->id] ?? []];
        }

        return $out;
    }

    /**
     * @param array{batch: ApApprovalBatch, invoices: list<ApInvoice>, decisions: list<ApApprovalDecision>, notified: list<string>} $bundle
     * @return array<string, mixed>
     */
    private function shape(array $bundle): array
    {
        return ApApprovalPresenter::batch($bundle['batch'], $bundle['invoices'], $bundle['decisions'], $bundle['notified'], $this->time->today());
    }

    // ---- input ---------------------------------------------------------------------------------

    /**
     * Each id once, the first occurrence kept. Anything that is not a non-empty text cannot be an invoice.
     *
     * @param list<mixed> $sent
     * @return list<string>
     */
    private function distinctIds(array $sent): array
    {
        $ids = [];
        foreach ($sent as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new ApInvalid(self::UNAVAILABLE_INVOICES);
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * `_approval_recipients`: absent or null means every Approver. Otherwise a list of bare addresses, each trimmed and
     * lower-cased, repeats dropped (order kept), at most 20.
     *
     * @param list<ApApprover> $approvers
     * @return list<string>
     * @throws ApInvalid
     */
    private static function recipients(mixed $value, array $approvers): array
    {
        if ($value === null) {
            return array_values(array_unique(array_filter(array_map(static fn (ApApprover $a): string => $a->email, $approvers), static fn (string $a): bool => $a !== '')));
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ApInvalid(self::RECIPIENTS_NOT_LIST);
        }
        $out = [];
        foreach ($value as $item) {
            $address = strtolower(trim(is_scalar($item) ? (string) $item : ''));
            if (!str_contains($address, '@') || !ApMailText::isAddress($address)) {
                throw new ApInvalid('“' . (is_scalar($item) ? (string) $item : '') . '” is not a valid email address');
            }
            if (!in_array($address, $out, true)) {
                $out[] = $address;
            }
        }
        if (count($out) > self::MAX_RECIPIENTS) {
            throw new ApInvalid('An approval request cannot notify more than ' . self::MAX_RECIPIENTS . ' addresses');
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** The name a row keeps for the person: their name, else their address. */
    private function name(ApActor $actor): string
    {
        $name = trim($actor->name);

        return $name !== '' ? $name : strtolower(trim($actor->email));
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(ApActor $actor, string $action, string $batchId, array $details): void
    {
        $this->audit->record(new ApAuditEvent($action, ApAuditEvent::ENTITY_APPROVAL_BATCH, $batchId, $details, $this->time->now(), $actor->userId, $actor->name));
    }
}
