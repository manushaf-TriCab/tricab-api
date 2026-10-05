<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Fixtures;

use DateTimeImmutable;
use TriCab\Core\Rfq\Application\DTO\SyncAttemptView;
use TriCab\Core\Rfq\Contract\RfqSyncStore;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncNeedsReconciliation;
use TriCab\Core\Rfq\Domain\Service\SyncAttemptTransitionPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Domain\ValueObject\SyncHeaderState;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\StoreProjection;

/**
 * The attempt ledger in memory, with the real policy's rules and the SQL
 * store's order of writes, for service tests that must see what was recorded
 * and in what order (`$log`). The SQL store's own behaviour is proved against
 * SQL Server in `tests/Rfq/RfqSyncLedgerTest.php`.
 */
final class InMemoryRfqSyncStore implements RfqSyncStore
{
    /** @var list<string> every write, in order: `begin`, `header_intent`, `header_ok`, `line:0`, `finish:succeeded`... */
    public array $log = [];

    /** @var array<string, array<string, mixed>> */
    public array $attempts = [];

    /** @var array<string, mixed> the draft's sync-relevant state, as the store would leave it */
    public array $draft = ['revision' => 3, 'status' => 'in_progress', 'refno' => null, 'refver' => null, 'locked' => false];

    public int $swept = 0;

    /** What `begin` last froze, for assertions. */
    public ?string $payload = null;
    public ?string $sha = null;

    /** @var array<string, mixed> */
    public array $rep = [];

    public function begin(string $draftId, int $expectedRevision, DraftActor $actor, array $rep, array $plan, string $payload, string $payloadSha256, string $correlationId, string $origin): ?string
    {
        $this->log[] = 'begin';
        if ($expectedRevision !== $this->draft['revision']) {
            throw new RfqRevisionConflict((int) $this->draft['revision'], $expectedRevision);
        }
        if ($this->draft['locked']) {
            throw RfqDraftNotEditable::syncBlocked('A sync for this RFQ is already running.');
        }
        $states = array_map(static fn (array $a): SyncAttemptState => $a['state'], $this->attempts);
        if (!SyncAttemptTransitionPolicy::mayEnqueue(array_values($states))) {
            foreach ($this->attempts as $attempt) {
                if ($attempt['state']->is(SyncAttemptState::UNCERTAIN)) {
                    throw new RfqSyncNeedsReconciliation($this->view($attempt));
                }
            }
            throw RfqDraftNotEditable::syncBlocked('A sync for this RFQ is already running.');
        }
        $id = 'a' . str_pad((string) (count($this->attempts) + 1), 31, '0', STR_PAD_LEFT);
        $this->attempts[$id] = [
            'id' => $id,
            'draft_id' => $draftId,
            'revision' => $expectedRevision,
            'state' => SyncAttemptState::fromString(SyncAttemptState::RUNNING),
            'header' => SyncHeaderState::fromString(SyncHeaderState::PENDING),
            'refno' => null,
            'refver' => null,
            'lines_total' => count($plan['line_items'] ?? []),
            'lines_sent' => 0,
            'lines_failed' => 0,
            'error' => null,
            'note' => null,
            'sha' => $payloadSha256,
            'correlation_id' => $correlationId,
            'actor' => $actor->email,
        ];
        $this->draft['locked'] = true;
        $this->payload = $payload;
        $this->sha = $payloadSha256;
        $this->rep = $rep;

        return $id;
    }

    public function noteHeaderIntent(string $attemptId): void
    {
        $this->log[] = 'header_intent';
        $this->attempts[$attemptId]['header'] = SyncHeaderState::fromString(SyncHeaderState::SENT);
    }

    public function noteHeaderOk(string $attemptId, string $refno, string $refver): void
    {
        $this->log[] = 'header_ok';
        $this->attempts[$attemptId]['header'] = SyncHeaderState::fromString(SyncHeaderState::CONFIRMED);
        $this->attempts[$attemptId]['refno'] = $refno;
        $this->attempts[$attemptId]['refver'] = $refver;
        $this->draft['refno'] ??= $refno;
        $this->draft['refver'] ??= $refver;
    }

    public function noteHeaderFailed(string $attemptId, string $error, bool $certain): void
    {
        $this->log[] = $certain ? 'header_rejected' : 'header_unknown';
        $this->attempts[$attemptId]['header'] = SyncHeaderState::fromString($certain ? SyncHeaderState::REJECTED : SyncHeaderState::UNKNOWN);
        $this->attempts[$attemptId]['error'] = $error;
    }

    public function noteLine(string $attemptId, int $index, bool $accepted, array $detail, int $nextLine): void
    {
        $this->log[] = 'line:' . $index . ($accepted ? '' : ':rejected');
        $this->attempts[$attemptId][$accepted ? 'lines_sent' : 'lines_failed']++;
    }

    public function finishAttempt(string $attemptId, SyncAttemptState $state, array $result, string $error, string $reconcileNote, DraftActor $actor): void
    {
        $attempt = $this->attempts[$attemptId];
        SyncAttemptTransitionPolicy::assertSystemMay($attempt['state'], $state);
        SyncAttemptTransitionPolicy::assertMayFinish($state, $attempt['header'], trim((string) $attempt['refno']) !== '');
        $this->log[] = 'finish:' . $state->toString();
        $this->attempts[$attemptId]['state'] = $state;
        $this->attempts[$attemptId]['error'] = $error !== '' ? $error : null;
        $this->attempts[$attemptId]['note'] = $reconcileNote !== '' ? $reconcileNote : null;
        $this->draft['locked'] = false;
        $this->draft['revision']++;
        $this->draft['status'] = $state->is(SyncAttemptState::SUCCEEDED) ? 'synced' : 'failed';
        if ($attempt['refno'] !== null) {
            $this->draft['refno'] = $attempt['refno'];
            $this->draft['refver'] = $attempt['refver'];
        }
    }

    public function attempt(string $attemptId): ?SyncAttemptView
    {
        return isset($this->attempts[$attemptId]) ? $this->view($this->attempts[$attemptId]) : null;
    }

    public function attemptsFor(string $draftId, int $limit): array
    {
        return array_map(fn (array $a): SyncAttemptView => $this->view($a), array_values($this->attempts));
    }

    public function sweepStale(string $draftId, string $beforeIso): int
    {
        $this->log[] = 'sweep';

        return $this->swept;
    }

    /** @param array<string, mixed> $a */
    private function view(array $a): SyncAttemptView
    {
        return StoreProjection::syncAttemptView([
            'draft_id' => $a['draft_id'],
            'revision' => $a['revision'],
            'state' => $a['state']->toString(),
            'header_state' => $a['header']->toString(),
            'attempt_no' => 1,
            'refno' => $a['refno'],
            'refver' => $a['refver'],
            'lines_total' => $a['lines_total'],
            'lines_sent' => $a['lines_sent'],
            'lines_failed' => $a['lines_failed'],
            'payload_sha256' => $a['sha'],
            'error' => $a['error'],
            'reconcile_note' => $a['note'],
            'created_at' => (new DateTimeImmutable('2026-10-02T02:00:00+00:00'))->format('c'),
            'updated_at' => (new DateTimeImmutable('2026-10-02T02:00:01+00:00'))->format('c'),
        ]);
    }
}
