<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/**
 * Invoices: list, read, create by hand, review and save, exclude a duplicate. The server re-runs every rule on every
 * read and write ({@see Ap2InvoiceRules}); nothing the browser says about validity, matching or approval is believed.
 * Every write appends to the supplier's history in the same transaction.
 *
 * Rules a write follows:
 *  - A posted invoice (import state Posted) is locked: only `paymentHold` may change, anything else is a 409.
 *  - A save that changes the fingerprint clears `approvalId`: an approval made on other values is not carried over.
 *  - The stage is derived, never taken from the body; `importState`, `approvalId`, `paid`, `excluded` and the Odoo
 *    references are not editable here either (their own routes and phases own them).
 */
final class Ap2InvoiceService
{
    public function __construct(
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2PersonDirectory $people,
        private readonly Ap2Assessor $assessor,
        private readonly Ap2Transactions $transactions,
        private readonly ApTime $time,
        private readonly Ap2FileService $files,
    ) {
    }

    /**
     * `GET /invoices?queue&supplierId`
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function list(array $params): array
    {
        $supplierId = $this->stringParam($params, 'supplierId');
        $queue = $this->stringParam($params, 'queue');
        $rows = $supplierId === '' ? $this->invoices->all() : $this->invoices->forSupplier($supplierId);
        $suppliers = $this->supplierMap();
        $assessments = $this->assessor->many($rows, $suppliers, $this->time->today());

        if ($queue !== '' && !in_array($queue, Ap2Queues::NAMES, true)) {
            throw new Ap2Invalid('Unknown queue', Ap2Failure::details(['queue must be one of ' . implode(', ', Ap2Queues::NAMES)]));
        }
        $today = $this->time->today();
        $out = [];
        foreach ($rows as $invoice) {
            $assessment = $assessments[$invoice->id];
            if ($queue !== '' && !Ap2Queues::matches($queue, $invoice, $suppliers[$invoice->supplierId] ?? null, $assessment, $today)) {
                continue;
            }
            $out[] = Ap2Presenter::invoice($invoice, $suppliers[$invoice->supplierId] ?? null, $assessment);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->present($this->found($id));
    }

    /**
     * `POST /invoices`
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(Ap2Actor $actor, array $body): array
    {
        $input = new Ap2Input($body);
        $this->read($input);
        $values = $input->values();
        $issues = $input->issues();
        if (!isset($values['supplierId']) && !in_array('supplierId must be an id', $issues, true)) {
            $issues[] = 'supplierId is required';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The invoice has invalid fields', Ap2Failure::details($issues));
        }
        $supplier = $this->suppliers->find($this->str($values, 'supplierId'));
        if ($supplier === null) {
            throw new Ap2Invalid('The supplier does not exist', Ap2Failure::details(['supplierId does not match a supplier']));
        }
        $owner = $this->owner($values);
        $settings = $this->settings->get();
        $now = $this->time->now();

        $values += ['entity' => $supplier->entity];
        if (!isset($values['total']) && (isset($values['net']) || isset($values['tax']))) {
            $values['total'] = round($this->num($values, 'net') + $this->num($values, 'tax'), 2);
        }
        foreach (['gl' => $settings->glDefault, 'taxCode' => $settings->taxDefault] as $key => $default) {
            if (($values[$key] ?? '') === '') {
                $values[$key] = $default;
            }
        }
        unset($values['ownerUserId']);

        $draft = new Ap2Invoice(bin2hex(random_bytes(16)), $this->str($values, 'supplierId'), $now, $now, owner: $owner);
        $invoice = $draft->with(...$values);
        $assessment = $this->assessor->one($invoice, $supplier, $this->time->today());
        $invoice = $invoice->with(stage: Ap2InvoiceRules::stage($invoice, $assessment->validation, $assessment->approvalCurrent));

        $this->transactions->run(function () use ($invoice, $actor, $now, $assessment): void {
            $this->invoices->insert($invoice);
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $invoice->supplierId,
                'Invoice created',
                $invoice->number . ' · ' . ($assessment->validation === [] ? 'Checks passed' : implode('; ', $assessment->validation)),
                $actor->person(),
                $now,
                $invoice->id,
            ));
        });

        return $this->present($invoice);
    }

    /**
     * `GET /invoices/{id}/file`: the source document, 404 when there is none.
     */
    public function file(string $id, ?int $viewerUserId): Ap2File
    {
        $invoice = $this->found($id);
        if ($invoice->sourceFileId === null) {
            throw new Ap2NotFound('No document');
        }

        return $this->files->read($invoice->sourceFileId, $viewerUserId);
    }

    /**
     * An invoice made from a document in an email (`POST /mail/{id}/create-invoice`). `$fields` are the values the
     * extraction found, keyed by invoice property (anything missing takes the default); the rules then say what is
     * still wrong, so a thin extraction simply lands in "Needs review". Joins the caller's transaction.
     *
     * @param array<string, mixed> $fields
     */
    public function createFromSource(Ap2Actor $actor, Ap2Supplier $supplier, array $fields, string $mailId, ?string $fileId, string $sourceDoc, string $fingerprint, ?float $confidence): Ap2Invoice
    {
        $settings = $this->settings->get();
        $now = $this->time->now();
        $values = $fields + ['entity' => $supplier->entity];
        if (($values['entity'] ?? '') === '') {
            $values['entity'] = $supplier->entity;
        }
        if (!isset($values['total']) && (isset($values['net']) || isset($values['tax']))) {
            $values['total'] = round($this->num($values, 'net') + $this->num($values, 'tax'), 2);
        }
        foreach (['gl' => $settings->glDefault, 'taxCode' => $settings->taxDefault] as $key => $default) {
            if (($values[$key] ?? '') === '') {
                $values[$key] = $default;
            }
        }
        $draft = new Ap2Invoice(bin2hex(random_bytes(16)), $supplier->id, $now, $now);
        $invoice = $draft->with(...$values)->with(sourceDoc: mb_substr($sourceDoc, 0, 255), sourceFileId: $fileId, sourceFingerprint: $fingerprint, confidence: $confidence, mailId: $mailId);
        $assessment = $this->assessor->one($invoice, $supplier, $this->time->today());
        $invoice = $invoice->with(stage: Ap2InvoiceRules::stage($invoice, $assessment->validation, $assessment->approvalCurrent));

        $this->transactions->run(function () use ($invoice, $actor, $now, $assessment): void {
            $this->invoices->insert($invoice);
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $invoice->supplierId,
                'Invoice created from email',
                ($invoice->number === '' ? 'No number read' : $invoice->number) . ' · ' . ($assessment->validation === [] ? 'Checks passed' : implode('; ', $assessment->validation)),
                $actor->person(),
                $now,
                $invoice->id,
                $invoice->mailId,
            ));
        });

        return $invoice;
    }

    /**
     * `PATCH /invoices/{id}`: review and save.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(Ap2Actor $actor, string $id, array $body): array
    {
        $before = $this->found($id);
        $input = new Ap2Input($body);
        $this->read($input);
        $values = $input->values();
        if ($input->issues() !== []) {
            throw new Ap2Invalid('The invoice has invalid fields', Ap2Failure::details($input->issues()));
        }

        if ($before->isPosted()) {
            $locked = array_values(array_diff(array_keys($values), ['paymentHold']));
            if ($locked !== []) {
                throw new Ap2Conflict(
                    'Posted invoices are locked: only the payment hold can change',
                    Ap2Failure::details(array_map(static fn (string $key): string => $key . ' cannot change on a posted invoice; adjustments need a controlled accounting process', $locked)),
                );
            }
        }

        $owner = array_key_exists('ownerUserId', $values) ? $this->owner($values) : $before->owner;
        unset($values['ownerUserId']);
        $newSupplierId = isset($values['supplierId']) ? $this->str($values, 'supplierId') : $before->supplierId;
        $oldSupplier = $this->suppliers->find($before->supplierId);
        $supplier = $newSupplierId === $before->supplierId ? $oldSupplier : $this->suppliers->find($newSupplierId);
        if ($supplier === null) {
            throw new Ap2Invalid('The supplier does not exist', Ap2Failure::details(['supplierId does not match a supplier']));
        }

        $changed = $before->with(...$values)->with(owner: $owner);
        $fingerprintMoved = Ap2InvoiceRules::fingerprint($before, $oldSupplier) !== Ap2InvoiceRules::fingerprint($changed, $supplier);
        $clearedApproval = $fingerprintMoved && $changed->approvalId !== null;
        if ($clearedApproval) {
            $changed = $changed->with(approvalId: null);
        }
        $assessment = $this->assessor->one($changed, $supplier, $this->time->today());
        $changed = $changed->with(stage: Ap2InvoiceRules::stage($changed, $assessment->validation, $assessment->approvalCurrent));

        if ($changed == $before) {
            return $this->present($before);
        }
        $now = $this->time->now();
        $changed = $changed->with(updatedAt: $now);

        $this->transactions->run(function () use ($before, $changed, $actor, $now, $assessment, $values, $newSupplierId, $supplier, $clearedApproval): void {
            $this->invoices->update($changed);
            $holdOnly = array_keys($values) === ['paymentHold'];
            $this->record($actor, $now, $changed, $holdOnly
                ? ($changed->paymentHold ? 'Payment hold set' : 'Payment hold released')
                : 'Invoice review saved', $changed->number . ($holdOnly ? '' : ' · ' . ($assessment->validation === [] ? 'Checks passed' : implode('; ', $assessment->validation))));
            if ($clearedApproval) {
                $this->record($actor, $now, $changed, 'Approval superseded by edit', $changed->number . ' · the approved values changed, so the approval no longer applies', ['approvalId' => $before->approvalId]);
            }
            if ($newSupplierId !== $before->supplierId) {
                $this->history->append(new Ap2HistoryEntry(
                    bin2hex(random_bytes(16)),
                    $before->supplierId,
                    'Invoice reassigned',
                    $before->number . ' moved to ' . $supplier->name,
                    $actor->person(),
                    $now,
                    $changed->id,
                ));
            }
        });

        return $this->present($changed);
    }

    /**
     * `POST /invoices/{id}/exclude-duplicate`: retire a suspected duplicate. It stays in the register for audit.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function excludeDuplicate(Ap2Actor $actor, string $id, array $body): array
    {
        $invoice = $this->found($id);
        $input = new Ap2Input($body);
        $input->text('reason', 500, true);
        $issues = $input->issues();
        if (!$input->has('reason')) {
            $issues[] = 'reason is required';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('A reason is required to exclude a duplicate', Ap2Failure::details($issues));
        }
        $reason = $this->str($input->values(), 'reason');

        if ($invoice->isPosted()) {
            throw new Ap2Conflict('Posted records require controlled correction', Ap2Failure::details(['The invoice is posted in Odoo, so it cannot be excluded here']));
        }
        if ($invoice->excluded) {
            throw new Ap2Conflict('The invoice is already excluded', Ap2Failure::details(['Already excluded']));
        }
        $supplier = $this->suppliers->find($invoice->supplierId);
        $duplicates = Ap2InvoiceRules::duplicates($invoice, $this->invoices->forSupplier($invoice->supplierId));
        if ($duplicates === []) {
            throw new Ap2Conflict('The invoice is not a suspected duplicate', Ap2Failure::details(['No other invoice has the same supplier, entity and number or the same source document']));
        }

        $now = $this->time->now();
        $excluded = $invoice->with(excluded: true, excludedReason: $reason, stage: 'Excluded', approvalId: null, updatedAt: $now);
        $this->transactions->run(function () use ($excluded, $actor, $now, $reason, $duplicates, $invoice): void {
            $this->invoices->update($excluded);
            $this->record($actor, $now, $excluded, 'Duplicate excluded', $excluded->number . ' retained for traceability · ' . $reason, [
                'duplicateOf' => array_map(static fn (Ap2Invoice $x): string => $x->id, $duplicates),
                'approvalId' => $invoice->approvalId,
            ]);
        });

        return Ap2Presenter::invoice($excluded, $supplier, $this->assessor->one($excluded, $supplier, $this->time->today()));
    }

    private function read(Ap2Input $input): void
    {
        $input->id('supplierId');
        $input->text('number', 100);
        $input->text('entity', 200);
        $input->choice('currency', Ap2Invoice::CURRENCIES);
        foreach (['net', 'tax', 'total', 'credit', 'creditNet', 'creditTax'] as $key) {
            $input->money($key);
        }
        foreach (['quantity', 'orderedQty', 'receivedQty', 'poUnitPrice'] as $key) {
            $input->quantity($key);
        }
        $input->date('issued');
        $input->date('due');
        $input->choice('kind', Ap2Invoice::KINDS);
        $input->text('po', 100);
        $input->text('receiptRef', 200);
        $input->text('noPoReason', 500);
        $input->text('gl', 50);
        $input->text('taxCode', 50);
        $input->text('costCentre', 100);
        $input->text('creditRef', 100);
        $input->text('sourceDoc', 255);
        foreach (['serviceAccepted', 'creditResolved', 'verified', 'paymentHold'] as $key) {
            $input->flag($key);
        }
        $input->userId('ownerUserId');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function owner(array $values): ?Ap2Person
    {
        $userId = $values['ownerUserId'] ?? null;
        if ($userId === null) {
            return null;
        }
        $person = is_int($userId) ? $this->people->find($userId) : null;
        if ($person === null) {
            throw new Ap2Invalid('The owner is not a Tri-AP2 user', Ap2Failure::details(['ownerUserId is not an active Tri-AP2 user']));
        }

        return $person;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function record(Ap2Actor $actor, \DateTimeImmutable $at, Ap2Invoice $invoice, string $type, string $detail, array $extra = []): void
    {
        $this->history->append(new Ap2HistoryEntry(bin2hex(random_bytes(16)), $invoice->supplierId, $type, $detail, $actor->person(), $at, $invoice->id, null, $extra));
    }

    private function found(string $id): Ap2Invoice
    {
        $invoice = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->invoices->find($id) : null;
        if ($invoice === null) {
            throw new Ap2NotFound('Invoice not found');
        }

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function present(Ap2Invoice $invoice): array
    {
        $supplier = $this->suppliers->find($invoice->supplierId);

        return Ap2Presenter::invoice($invoice, $supplier, $this->assessor->one($invoice, $supplier, $this->time->today()));
    }

    /** @return array<string, Ap2Supplier> */
    private function supplierMap(): array
    {
        $map = [];
        foreach ($this->suppliers->all() as $supplier) {
            $map[$supplier->id] = $supplier;
        }

        return $map;
    }

    /** @param array<string, mixed> $params */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $values */
    private function str(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $values */
    private function num(array $values, string $key): float
    {
        $value = $values[$key] ?? 0.0;

        return is_float($value) || is_int($value) ? (float) $value : 0.0;
    }
}
