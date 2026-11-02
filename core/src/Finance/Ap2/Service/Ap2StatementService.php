<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailDrafts;
use TriCab\Core\Finance\Ap2\Contract\Ap2StatementStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Domain\Ap2Statement;
use TriCab\Core\Finance\Ap2\Domain\Ap2Upstream;

/**
 * Supplier statements and their reconciliation. `postedAp` is Odoo's balance for the supplier as at the statement date,
 * in the statement's own currency (lines in other currencies left out; no conversion), and `difference` is statement
 * less Odoo. Both are null when Odoo cannot be read or the supplier is not linked to Odoo. A
 * review records Matched or Discrepancy with evidence and notes; it never changes an invoice or a balance.
 * The server works out which one it is; a status the client sends that disagrees is a 409.
 */
final class Ap2StatementService
{
    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly Ap2StatementStore $statements,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2OdooLedgerService $ledger,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2MailDrafts $mail,
        private readonly Ap2Transactions $transactions,
        private readonly Ap2Time $time,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $statements = $this->statements->all();
        $balances = $this->balances($statements);

        return array_map(static fn (Ap2Statement $s): array => Ap2Presenter::statement($s, $balances[$s->id] ?? null), $statements);
    }

    /**
     * `POST /statements`: record a statement by hand until mail intake links them. `{supplierId, date, amount, currency?, reference?, mailId?}`
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(Ap2Actor $actor, array $body): array
    {
        $input = new Ap2Input($body);
        $input->id('supplierId');
        $input->date('date');
        $input->money('amount');
        $input->choice('currency', Ap2Invoice::CURRENCIES);
        $input->text('reference', 255);
        $input->id('mailId');
        $issues = $input->issues();
        foreach (['supplierId', 'amount'] as $key) {
            if (!$input->has($key)) {
                $issues[] = $key . ' is required';
            }
        }
        $values = $input->values();
        if (!is_string($values['date'] ?? null) && !in_array('date must be a date as yyyy-mm-dd', $issues, true)) {
            $issues[] = 'date is required';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The statement is invalid', Ap2Failure::details($issues));
        }
        $supplier = $this->suppliers->find(is_string($values['supplierId'] ?? null) ? $values['supplierId'] : '')
            ?? throw new Ap2Invalid('The supplier does not exist', Ap2Failure::details(['supplierId does not match a supplier']));
        $mailId = is_string($values['mailId'] ?? null) ? $values['mailId'] : null;
        if ($mailId !== null && $this->mail->find($mailId) === null) {
            throw new Ap2Invalid('The email does not exist', Ap2Failure::details(['mailId does not match an email']));
        }
        $now = $this->time->now();
        $statement = new Ap2Statement(
            bin2hex(random_bytes(16)),
            $supplier->id,
            is_string($values['date'] ?? null) ? $values['date'] : $this->time->today(),
            is_float($values['amount'] ?? null) ? $values['amount'] : 0.0,
            $now,
            $now,
            is_string($values['currency'] ?? null) ? $values['currency'] : 'AUD',
            is_string($values['reference'] ?? null) ? $values['reference'] : '',
            mailId: $mailId,
        );

        $this->transactions->run(function () use ($statement, $actor, $now, $supplier): void {
            $this->statements->insert($statement);
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $supplier->id,
                'Statement recorded',
                ($statement->reference === '' ? 'Statement' : $statement->reference) . ' · ' . $statement->currency . ' ' . number_format($statement->amount, 2) . ' · ' . $statement->date,
                $actor->person(),
                $now,
                null,
                null,
                ['statementId' => $statement->id],
            ));
        });

        return $this->present($statement);
    }

    /**
     * `POST /statements/{id}/review`: `{status?, evidence, notes}`.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function review(Ap2Actor $actor, string $id, array $body): array
    {
        $statement = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->statements->find($id) : null;
        if ($statement === null) {
            throw new Ap2NotFound('Statement not found');
        }
        $input = new Ap2Input($body);
        $input->choice('status', ['Matched', 'Discrepancy']);
        $input->text('evidence', 4000, true);
        $input->text('notes', 10000, true);
        $issues = $input->issues();
        foreach (['evidence', 'notes'] as $key) {
            if (!$input->has($key)) {
                $issues[] = $key . ' is required';
            }
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The review is invalid', Ap2Failure::details($issues));
        }
        $values = $input->values();

        $view = $this->present($statement);
        $difference = $view['difference'] ?? null;
        if (!is_float($difference) && !is_int($difference)) {
            throw new Ap2Upstream('The Odoo balance for this statement could not be read', Ap2Failure::details([
                'Odoo did not answer, or the supplier is not linked to Odoo; nothing was reviewed',
            ]));
        }
        $difference = (float) $difference;
        $computed = abs($difference) < self::TOLERANCE ? 'Matched' : 'Discrepancy';
        if (isset($values['status']) && $values['status'] !== $computed) {
            throw new Ap2Conflict('The statement ' . ($computed === 'Matched' ? 'matches the ledger' : 'differs from the ledger'), Ap2Failure::details([
                'status must be ' . $computed . ': the difference is ' . number_format($difference, 2),
            ]));
        }

        $now = $this->time->now();
        $reviewed = $statement->with(
            status: $computed,
            evidence: is_string($values['evidence'] ?? null) ? $values['evidence'] : '',
            notes: is_string($values['notes'] ?? null) ? $values['notes'] : '',
            reviewedBy: $actor->person(),
            reviewedAt: $now,
            updatedAt: $now,
        );
        $this->transactions->run(function () use ($reviewed, $actor, $now, $difference): void {
            $this->statements->update($reviewed);
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $reviewed->supplierId,
                'Statement ' . strtolower($reviewed->status),
                ($reviewed->reference === '' ? 'Statement' : $reviewed->reference) . ' · difference ' . number_format($difference, 2) . ' · ' . $reviewed->notes,
                $actor->person(),
                $now,
                null,
                null,
                ['statementId' => $reviewed->id, 'evidence' => $reviewed->evidence],
            ));
        });

        return $this->present($reviewed);
    }

    /** @return array<string, mixed> */
    private function present(Ap2Statement $s): array
    {
        return Ap2Presenter::statement($s, $this->balances([$s])[$s->id] ?? null);
    }

    /**
     * @param list<Ap2Statement> $statements
     * @return array<string, float|null>
     */
    private function balances(array $statements): array
    {
        try {
            return $this->ledger->statementBalances($statements);
        } catch (Ap2Upstream) {
            return [];
        }
    }
}
