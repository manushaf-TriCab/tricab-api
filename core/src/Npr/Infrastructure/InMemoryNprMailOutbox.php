<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Npr\Mail\NewNprOutboxEntry;
use TriCab\Core\Npr\Mail\NprMailOutbox;
use TriCab\Core\Npr\Mail\NprOutboxDelivery;
use TriCab\Core\Npr\Mail\NprOutboxEntry;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/** The outbox held in memory: the default binding without a database, and the test double. */
final class InMemoryNprMailOutbox implements NprMailOutbox
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private int $next = 0;
    private int $nextDelivery = 0;

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function enqueue(NewNprOutboxEntry $entry, int $activityId, int $requestId): int
    {
        $id = ++$this->next;
        $now = $this->clock->now();
        $deliveries = [];
        foreach ([['to', $entry->to], ['cc', $entry->cc]] as [$type, $addresses]) {
            foreach ($addresses as $address) {
                $deliveries[] = new NprOutboxDelivery(++$this->nextDelivery, $address, $type, $entry->status === NewNprOutboxEntry::STATUS_SUPPRESSED ? 'suppressed' : 'pending', 0, null, null);
            }
        }
        foreach ($entry->missing as $missing) {
            $deliveries[] = new NprOutboxDelivery(
                ++$this->nextDelivery,
                NprOutboxDelivery::MISSING_PREFIX . $missing['role'] . ')',
                $missing['type'],
                'failed',
                0,
                null,
                'No address is configured for ' . $missing['role'] . '.',
            );
        }
        $this->rows[$id] = [
            'entry' => $entry,
            'status' => $entry->status,
            'attempts' => 0,
            'lastAttemptAt' => null,
            'error' => null,
            'createdAt' => $now,
            'deliveries' => $deliveries,
        ];

        return $id;
    }

    public function pendingForRequest(string $requestPublicId): array
    {
        return $this->filter(fn (array $row): bool => $row['status'] === 'pending' && $row['entry']->requestPublicId === $requestPublicId);
    }

    public function retryable(int $limit): array
    {
        return array_slice($this->filter(
            static fn (array $row): bool => $row['status'] === 'pending' || ($row['status'] === 'failed' && $row['attempts'] > 0 && $row['attempts'] < self::MAX_ATTEMPTS),
        ), 0, $limit);
    }

    public function find(int $id): ?NprOutboxEntry
    {
        return isset($this->rows[$id]) ? $this->entry($id) : null;
    }

    public function recent(int $limit, ?string $status): array
    {
        $rows = $this->filter(static fn (array $row): bool => $status === null || $row['status'] === $status);

        return array_slice(array_reverse($rows), 0, $limit);
    }

    public function outstanding(): int
    {
        return count($this->retryable(PHP_INT_MAX));
    }

    public function recordAttempt(int $id, bool $sent, ?string $error, DateTimeImmutable $now): void
    {
        $row = &$this->rows[$id];
        $row['attempts']++;
        $row['lastAttemptAt'] = $now;
        $row['status'] = $sent ? 'sent' : 'failed';
        $row['error'] = $sent ? null : $error;
        foreach ($row['deliveries'] as $i => $delivery) {
            if ($delivery->isMissingRecipient()) {
                continue;
            }
            $row['deliveries'][$i] = new NprOutboxDelivery($delivery->id, $delivery->email, $delivery->type, $sent ? 'sent' : 'failed', $delivery->attempts + 1, $sent ? $now : null, $sent ? null : $error);
        }
    }

    public function reopen(int $id): bool
    {
        if (!isset($this->rows[$id]) || !in_array($this->rows[$id]['status'], ['failed', 'suppressed'], true)) {
            return false;
        }
        $row = &$this->rows[$id];
        $row['status'] = 'pending';
        $row['attempts'] = 0;
        $row['error'] = null;
        foreach ($row['deliveries'] as $i => $delivery) {
            if (!$delivery->isMissingRecipient()) {
                $row['deliveries'][$i] = new NprOutboxDelivery($delivery->id, $delivery->email, $delivery->type, 'pending', 0, null, null);
            }
        }

        return true;
    }

    /**
     * Test seam: every row, oldest first.
     *
     * @return list<NprOutboxEntry>
     */
    public function all(): array
    {
        return $this->filter(static fn (array $row): bool => true);
    }

    /**
     * @param callable(array<string, mixed>): bool $keep
     * @return list<NprOutboxEntry>
     */
    private function filter(callable $keep): array
    {
        $out = [];
        foreach ($this->rows as $id => $row) {
            if ($keep($row)) {
                $out[] = $this->entry($id);
            }
        }

        return $out;
    }

    private function entry(int $id): NprOutboxEntry
    {
        $row = $this->rows[$id];
        /** @var NewNprOutboxEntry $entry */
        $entry = $row['entry'];

        return new NprOutboxEntry(
            $id,
            $entry->requestPublicId,
            (string) ($entry->payload['requestNumber'] ?? ''),
            $entry->event,
            $entry->to,
            $entry->cc,
            $entry->payload,
            $row['status'],
            $row['attempts'],
            $row['lastAttemptAt'],
            $row['error'],
            $row['createdAt'],
            $row['deliveries'],
        );
    }
}
