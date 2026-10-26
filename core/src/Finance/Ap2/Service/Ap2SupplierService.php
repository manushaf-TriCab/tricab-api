<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Outbox;
use TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Domain\Ap2OutboxMessage;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/**
 * Suppliers and their activity ledger. `bankVerified` and `bankChange` are not editable here: only the bank
 * verification route changes them, because an invoice approval is bound to them.
 */
final class Ap2SupplierService
{
    /** What a person may record in a supplier's history by hand. */
    public const ACTION_TYPES = ['Call', 'Note', 'Supplier follow-up', 'Receipt escalation', 'Dispute'];

    public function __construct(
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2PersonDirectory $people,
        private readonly Ap2Transactions $transactions,
        private readonly Ap2Outbox $outbox,
        private readonly ApTime $time,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $positions = Ap2Positions::bySupplier($this->invoices->all(), $this->fx(), $this->time->today());

        return array_map(
            static fn (Ap2Supplier $s): array => Ap2Presenter::supplier($s, $positions[$s->id] ?? ['balance' => 0.0, 'overdue' => 0.0, 'openInvoices' => 0]),
            $this->suppliers->all(),
        );
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->present($this->found($id));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(Ap2Actor $actor, array $body): array
    {
        $input = $this->read($body);
        $values = $input->values();
        $issues = $input->issues();
        if (!isset($values['name']) && !in_array('name is required', $issues, true)) {
            $issues[] = 'name is required';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The supplier has invalid fields', Ap2Failure::details($issues));
        }
        $owner = $this->owner($values);
        unset($values['ownerUserId']);

        $now = $this->time->now();
        $name = is_string($values['name'] ?? null) ? $values['name'] : '';
        $supplier = (new Ap2Supplier(bin2hex(random_bytes(16)), $name, $now, $now))->with(...$values)->with(owner: $owner);

        $this->transactions->run(function () use ($supplier, $actor, $now): void {
            $this->suppliers->insert($supplier);
            $this->history->append(new Ap2HistoryEntry(bin2hex(random_bytes(16)), $supplier->id, 'Supplier created', $supplier->name, $actor->person(), $now));
        });

        return $this->present($supplier);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(Ap2Actor $actor, string $id, array $body): array
    {
        $before = $this->found($id);
        $input = $this->read($body);
        $values = $input->values();
        if ($input->issues() !== []) {
            throw new Ap2Invalid('The supplier has invalid fields', Ap2Failure::details($input->issues()));
        }
        $owner = array_key_exists('ownerUserId', $values) ? $this->owner($values) : $before->owner;
        unset($values['ownerUserId']);

        $changed = $before->with(...$values)->with(owner: $owner);
        if ($changed == $before) {
            return $this->present($before);
        }
        $now = $this->time->now();
        $changed = $changed->with(updatedAt: $now);
        $fields = array_keys($values);
        if ($owner?->userId !== $before->owner?->userId) {
            $fields[] = 'owner';
        }

        $this->transactions->run(function () use ($changed, $actor, $now, $fields): void {
            $this->suppliers->update($changed);
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $changed->id,
                'Supplier details updated',
                $changed->name . ' · ' . implode(', ', $fields),
                $actor->person(),
                $now,
            ));
        });

        return $this->present($changed);
    }

    /**
     * `GET /suppliers/{id}/history?from&to`: newest first, the Sydney calendar days `from` to `to` inclusive.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function history(string $id, array $params): array
    {
        $this->found($id);
        $input = new Ap2Input(array_intersect_key($params, ['from' => 1, 'to' => 1]));
        $input->date('from');
        $input->date('to');
        if ($input->issues() !== []) {
            throw new Ap2Invalid('The period is invalid', Ap2Failure::details($input->issues()));
        }
        $values = $input->values();
        $from = is_string($values['from'] ?? null) ? $values['from'] : null;
        $to = is_string($values['to'] ?? null) ? $values['to'] : null;
        if ($from !== null && $to !== null && $to < $from) {
            throw new Ap2Invalid('The period is invalid', Ap2Failure::details(['to must not be before from']));
        }
        [$start, $until] = $this->time->dayBounds($from, $to);

        return array_map(Ap2Presenter::history(...), $this->history->forSupplier($id, $start, $until));
    }

    /**
     * `POST /suppliers/{id}/history`: a reviewed call, note, follow-up, escalation or dispute.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function recordAction(Ap2Actor $actor, string $id, array $body): array
    {
        $this->found($id);
        $input = new Ap2Input($body);
        $input->choice('type', self::ACTION_TYPES);
        $input->text('detail', 4000, true);
        $issues = $input->issues();
        foreach (['type', 'detail'] as $key) {
            if (!$input->has($key)) {
                $issues[] = $key . ' is required';
            }
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The action is invalid', Ap2Failure::details($issues));
        }
        $values = $input->values();
        $entry = new Ap2HistoryEntry(
            bin2hex(random_bytes(16)),
            $id,
            is_string($values['type'] ?? null) ? $values['type'] : 'Note',
            is_string($values['detail'] ?? null) ? $values['detail'] : '',
            $actor->person(),
            $this->time->now(),
            null,
            null,
            ['source' => 'Reviewed manual entry'],
        );
        $this->history->append($entry);

        return Ap2Presenter::history($entry);
    }

    /**
     * `POST /suppliers/{id}/bank-verify`: `{evidence}`. A person with BANK.VERIFY records that they confirmed the bank
     * details through a trusted channel independent of the email. It clears the bank change and the hold and sets
     * bankVerified. Those flags are in every invoice fingerprint, so approvals made before it go stale on their own.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function bankVerify(Ap2Actor $actor, string $id, array $body): array
    {
        $supplier = $this->found($id);
        $input = new Ap2Input($body);
        $input->text('evidence', 2000, true);
        $issues = $input->issues();
        if (!$input->has('evidence')) {
            $issues[] = 'evidence is required';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('Verification evidence is required', Ap2Failure::details($issues));
        }
        if ($supplier->bankVerified && !$supplier->bankChange && !$supplier->hold) {
            throw new Ap2Conflict('Nothing to verify', Ap2Failure::details(['The supplier bank details are already verified']));
        }
        $values = $input->values();
        $evidence = is_string($values['evidence'] ?? null) ? $values['evidence'] : '';
        $now = $this->time->now();
        $verified = $supplier->with(bankVerified: true, bankChange: false, hold: false, updatedAt: $now);

        $this->transactions->run(function () use ($verified, $supplier, $actor, $now, $evidence): void {
            $this->suppliers->update($verified);
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $verified->id,
                'Bank verification recorded',
                $evidence . ' · approvals made before this may be stale',
                $actor->person(),
                $now,
                null,
                null,
                ['evidence' => $evidence, 'hadBankChange' => $supplier->bankChange, 'hadHold' => $supplier->hold],
            ));
        });

        return $this->present($verified);
    }

    /**
     * `POST /suppliers/{id}/history/share`: `{historyIds[], to, cc, subject, body, reviewed: true}`. The person has
     * reviewed the entries and the recipients; the email goes through the outbox.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function shareHistory(Ap2Actor $actor, string $id, array $body): array
    {
        $supplier = $this->found($id);
        $input = new Ap2Input($body);
        $input->addresses('to', 1000);
        $input->addresses('cc', 1000);
        $input->text('subject', 400, true);
        $input->text('body', 100000, true);
        $issues = $input->issues();
        foreach (['subject', 'body'] as $key) {
            if (!$input->has($key)) {
                $issues[] = $key . ' is required';
            }
        }
        if (!$input->has('to') || (($input->values()['to'] ?? '') === '' && preg_grep('/^to /', $issues) === [])) {
            $issues[] = 'to is required';
        }
        if (($body['reviewed'] ?? null) !== true) {
            $issues[] = 'reviewed must be true: review the entries and recipients first';
        }
        $raw = $body['historyIds'] ?? null;
        $wanted = [];
        if (is_array($raw) && $raw !== [] && array_is_list($raw)) {
            foreach ($raw as $one) {
                if (is_string($one) && preg_match('/^[0-9a-f]{32}$/', $one) === 1) {
                    $wanted[$one] = $one;
                } else {
                    $wanted = [];
                    break;
                }
            }
        }
        if ($wanted === []) {
            $issues[] = 'historyIds must be a list of history entry ids';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The history share is invalid', Ap2Failure::details(array_values(array_unique($issues))));
        }
        $entries = array_values(array_filter($this->history->forSupplier($supplier->id), static fn (Ap2HistoryEntry $e): bool => isset($wanted[$e->id])));
        if (count($entries) !== count($wanted)) {
            throw new Ap2Invalid('The history share is invalid', Ap2Failure::details(['historyIds must all be entries of this supplier']));
        }
        $values = $input->values();
        $to = is_string($values['to'] ?? null) ? $values['to'] : '';
        $cc = is_string($values['cc'] ?? null) ? $values['cc'] : '';
        $subject = is_string($values['subject'] ?? null) ? $values['subject'] : '';
        $text = is_string($values['body'] ?? null) ? $values['body'] : '';
        $now = $this->time->now();

        $this->transactions->run(function () use ($supplier, $actor, $now, $to, $cc, $subject, $text, $entries): void {
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $supplier->id,
                'History shared',
                count($entries) . ' entries shared to ' . $to,
                $actor->person(),
                $now,
                null,
                null,
                ['to' => $to, 'cc' => $cc, 'subject' => $subject, 'historyIds' => array_map(static fn (Ap2HistoryEntry $e): string => $e->id, $entries)],
            ));
            $this->outbox->queue(new Ap2OutboxMessage(Ap2OutboxMessage::HISTORY_SHARE, $supplier->id, $actor->person(), $to, $subject, $text, $cc));
        });

        return ['shared' => count($entries), 'to' => $to, 'cc' => $cc, 'subject' => $subject, 'sent' => false];
    }

    /** @param array<string, mixed> $body */
    private function read(array $body): Ap2Input
    {
        $input = new Ap2Input($body);
        $input->text('name', 200, true);
        $input->text('industry', 100);
        $input->addresses('email', 320);
        $input->text('contact', 200);
        $input->addresses('cc', 1000);
        $input->text('entity', 200);
        $input->text('phone', 50);
        $input->whole('terms', 365);
        $input->flag('hold');
        $input->text('notes', 10000);
        $input->userId('ownerUserId');
        $input->userId('odooPartnerId');

        return $input;
    }

    /** @param array<string, mixed> $values */
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

    private function found(string $id): Ap2Supplier
    {
        $supplier = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->suppliers->find($id) : null;
        if ($supplier === null) {
            throw new Ap2NotFound('Supplier not found');
        }

        return $supplier;
    }

    /** @return array<string, mixed> */
    private function present(Ap2Supplier $supplier): array
    {
        $positions = Ap2Positions::bySupplier($this->invoices->forSupplier($supplier->id), $this->fx(), $this->time->today());

        return Ap2Presenter::supplier($supplier, $positions[$supplier->id] ?? ['balance' => 0.0, 'overdue' => 0.0, 'openInvoices' => 0]);
    }

    private function fx(): Ap2Fx
    {
        return new Ap2Fx($this->settings->get()->fxRates);
    }
}
