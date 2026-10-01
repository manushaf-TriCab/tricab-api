<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

use RuntimeException;

/**
 * In-memory stand-in for [cc_report].[NumberSequences] under UPDLOCK, HOLDLOCK.
 *
 * A missing year is created inside the transaction that allocates it. A second
 * transaction that asks for the same year blocks until the first commits or
 * rolls back, so two callers cannot both take sequence 1.
 */
final class CustomerContactReportSequenceBook
{
    /** @var array<int, int> */
    private array $committed = [];

    /**
     * @var array<int, array{holder: int, previous: ?int, value: int}>
     */
    private array $open = [];

    public function allocate(int $transactionId, int $year): int
    {
        $held = $this->open[$year] ?? null;
        if ($held !== null && $held['holder'] !== $transactionId) {
            throw new CustomerContactReportSequenceLocked($year);
        }

        if ($held === null) {
            $this->open[$year] = [
                'holder' => $transactionId,
                'previous' => array_key_exists($year, $this->committed) ? $this->committed[$year] : null,
                'value' => $this->committed[$year] ?? 0,
            ];
        }

        $this->open[$year]['value']++;

        return $this->open[$year]['value'];
    }

    public function commit(int $transactionId): void
    {
        foreach ($this->open as $year => $state) {
            if ($state['holder'] === $transactionId) {
                $this->committed[$year] = $state['value'];
                unset($this->open[$year]);
            }
        }
    }

    public function rollBack(int $transactionId): void
    {
        foreach ($this->open as $year => $state) {
            if ($state['holder'] === $transactionId) {
                unset($this->open[$year]);
            }
        }
    }

    public function committedValue(int $year): int
    {
        return $this->committed[$year] ?? 0;
    }

    public function committedYears(): int
    {
        return count($this->committed);
    }

    public function seedCommitted(int $year, int $lastSequence): void
    {
        if ($lastSequence < 0 || isset($this->open[$year])) {
            throw new RuntimeException('Cannot seed a sequence year that is locked or negative.');
        }

        if ($lastSequence === 0) {
            unset($this->committed[$year]);

            return;
        }

        $this->committed[$year] = $lastSequence;
    }
}
