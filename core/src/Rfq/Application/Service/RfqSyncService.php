<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use DateTimeZone;
use Throwable;
use TriCab\Core\Rfq\Application\DTO\SyncAttemptView;
use TriCab\Core\Rfq\Application\DTO\SyncOutcome;
use TriCab\Core\Rfq\Contract\RfqDefaultMargins;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Contract\RfqQuoteGateway;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Contract\RfqSyncStore;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncBlocked;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncNeedsReconciliation;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncUnavailable;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\Service\SyncAttemptTransitionPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Domain\ValueObject\RfqAction;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\PythonText;
use TriCab\Core\Rfq\Storage\SalesRepAttribution;
use TriCab\Core\Rfq\Storage\StoreFormat;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Sync\GatewayHeaderResult;
use TriCab\Core\Rfq\Sync\GatewayLineResult;
use TriCab\Core\Rfq\Sync\GatewayPdfResult;
use TriCab\Core\Rfq\Sync\SyncPayload;
use TriCab\Core\Shared\Time\Clock;

/**
 * Sync to 4js (parity plan E7.3; architecture doc §5.4; the source's
 * `api_drafts_push` and `matcher/sync_jobs.py`).
 *
 * **This is the only code that asks 4js to write**, and it does so only through
 * {@see RfqQuoteGateway}. Every test uses a recording fake; no live adapter is
 * wired anywhere, and the whole path is off unless `RFQ_SYNC_ENABLED` is exactly
 * `true`. Off, or on with nothing connected, {@see sync()} throws
 * {@see RfqSyncUnavailable} before reading anything and sends nothing.
 *
 * ## The sequence
 *
 *  1. Access, the revision held, not archived, not already in 4js, reads settled.
 *  2. The sales rep attribution ({@see SalesRepAttribution}): the worker becomes
 *     the assignee and the quote's author, as on a content save.
 *  3. The plan is asked of the parser from the **saved** revision (`quote_sync`:
 *     readiness, prices, the header, exclusions). Nothing the browser sent but the
 *     revision number; a quote that is not ready, or whose price the customer's
 *     rule would change, is refused here ({@see RfqSyncBlocked}) with nothing sent.
 *  4. **The intent is recorded before 4js is contacted**: under the draft's row
 *     lock the attempt is written (`running`, header `pending`) with the plan frozen
 *     as compact sorted JSON and its SHA-256, the correlation id and a local
 *     idempotency key, and the sync lock taken. An open or uncertain attempt is the
 *     lock; a second click is refused (409), never sent.
 *  5. The header: `sent` is written before the request, `confirmed` with its quote
 *     number the instant it returns, before the first line. A timeout is `unknown`
 *     and the attempt is `uncertain`: never retried, never "failed".
 *  6. The lines, one at a time in the order reviewed, each checkpointed.
 *  7. The PDF, best effort; its failure never fails the sync.
 *  8. The attempt and the draft are settled in one transaction: `succeeded` is the
 *     system's `completed`; anything else leaves the draft `failed` with the reason
 *     and keeps the quote number, so a retry raises a new version of the same quote.
 *
 * ## What it does not claim
 *
 * 4js has no idempotency key and no verified quote lookup, so a sync is
 * **at most once by local record, not exactly once**. Reconciling an uncertain
 * attempt, resuming a half-sent one and retrying only the PDF are not built; a
 * stale `running` attempt is closed (see {@see RfqSyncStore::sweepStale()}), never resumed.
 */
final class RfqSyncService
{
    /** The quote's date and its default validity are 4js dates; TriCab quotes in Melbourne. */
    public const QUOTE_TIME_ZONE = 'Australia/Melbourne';

    public function __construct(
        private readonly RfqParserClient $parser,
        private readonly RfqRepository $rfqs,
        private readonly RfqDraftStore $drafts,
        private readonly RfqSyncStore $store,
        private readonly RfqQuoteGateway $gateway,
        private readonly RfqOperatorDirectory $operators,
        private readonly ?RfqDefaultMargins $margins,
        private readonly Clock $clock,
        /** `RFQ_SYNC_ENABLED` was exactly `true`. */
        private readonly bool $enabled,
    ) {
    }

    /**
     * Whether a sync can run at all, and if not why: for the editor to draw
     * the control disabled with the reason, never to hide a refusal.
     *
     * @return array{available: bool, reason: ?string, code: ?string}
     */
    public function availability(): array
    {
        try {
            $this->assertAvailable();
        } catch (RfqSyncUnavailable $unavailable) {
            return ['available' => false, 'reason' => $unavailable->getMessage(), 'code' => $unavailable->reason];
        }

        return ['available' => true, 'reason' => null, 'code' => null];
    }

    /**
     * @return SyncOutcome|null null when there is no such RFQ for this actor
     *
     * @throws RfqSyncUnavailable           off, or nothing connected: nothing was sent
     * @throws RfqActionForbidden           without `RFQ.QUOTE.SYNC` on this RFQ
     * @throws RfqRevisionConflict          when somebody saved first
     * @throws RfqDraftNotEditable          archived, already in 4js, a sync or a document read in flight
     * @throws RfqSyncBlocked               not ready to send: nothing was sent
     * @throws RfqSyncNeedsReconciliation   an earlier attempt may have created a quote nobody has the number of
     * @throws RfqParserFailure             when the parser cannot plan the quote: nothing was sent
     * @throws RfqStoreUnavailable          where there is no RFQ store
     */
    public function sync(RfqActor $actor, string $email, string $publicId, int $expectedRevision, CorrelationId $id): ?SyncOutcome
    {
        $this->assertAvailable();

        $record = $this->rfqs->findRecord(strtolower(trim($publicId)));
        if ($record === null || !RfqAccessPolicy::canSee($actor, $record->ownership)) {
            return null;
        }
        $allowed = RfqAccessPolicy::allowedActions($actor, $record->ownership);
        if (!in_array(RfqAction::SYNC, $allowed, true)) {
            throw $record->ownership->archived ? RfqDraftNotEditable::archived() : new RfqActionForbidden('sync this RFQ');
        }
        $draftId = StoreProjection::draftId($publicId);
        if ($draftId === null) {
            return null;
        }

        // An attempt a dead worker left running is closed first, so a crash cannot hold the RFQ for ever.
        $this->store->sweepStale($draftId, StoreFormat::isoTime($this->clock->now()->modify('-' . SyncAttemptTransitionPolicy::STALE_AFTER_MINUTES . ' minutes')));

        $stored = $this->drafts->load($draftId);
        if ($stored === null) {
            return null;
        }
        if ($stored->revision !== $expectedRevision) {
            throw new RfqRevisionConflict($stored->revision, $expectedRevision);
        }
        if ($stored->status === 'synced') {
            throw RfqDraftNotEditable::alreadySynced(self::text($stored->columns['refno'] ?? null), self::text($stored->columns['refver'] ?? null));
        }
        $this->assertExtractionComplete($record->extractionState);

        $worker = new DraftActor($email, RfqAccessPolicy::isManager($actor) ? 'admin' : 'sales', $actor->branchCode, $this->operators->codeFor($email));
        $assignee = self::text($stored->columns['assignee_email'] ?? null);
        $rep = SalesRepAttribution::apply($stored->quoteDetails, $assignee, $worker, $worker->operatorCode);
        $details = $rep['quoteDetails'];

        $existing = self::text($stored->columns['refno'] ?? null);
        $version = $existing !== null ? self::nextVersion(self::text($stored->columns['refver'] ?? null)) : null;
        $origin = strtolower(trim((string) self::text($stored->columns['source'] ?? null))) === 'email' ? 'email' : 'portal';
        $rows = self::clean($stored->rows);

        $planned = $this->parser->syncPlan(
            $id,
            $rows,
            $details,
            $this->defaultMargins($details, $rows, $id),
            $this->clock->now()->setTimezone(new DateTimeZone(self::QUOTE_TIME_ZONE))->format('Y-m-d'),
            $existing,
            $version,
            $origin,
        );
        if ($planned->plan === null) {
            throw self::blocked($planned->problem ?? ['status' => 400, 'payload' => []], $actor->can(Module::PERMISSION_PRICING_VIEW));
        }
        $plan = $planned->plan;
        $payload = SyncPayload::encode($plan);
        $sha = SyncPayload::sha256($payload);

        // The intent is recorded here, under the row lock, before 4js hears anything.
        $attemptId = $this->store->begin($draftId, $expectedRevision, $worker, $rep, $plan, $payload, $sha, $id->toString(), $origin);
        if ($attemptId === null) {
            return null;
        }

        return $this->execute($attemptId, $plan, $worker);
    }

    /** @throws RfqSyncUnavailable */
    private function assertAvailable(): void
    {
        if (!$this->enabled) {
            throw RfqSyncUnavailable::disabled();
        }
        if (!$this->gateway->isConnected()) {
            throw RfqSyncUnavailable::notConnected($this->gateway->unavailableNote());
        }
    }

    /**
     * The platform's tightening of the source (architecture doc §5.2): nothing
     * is quoted while a document is still being read, a read needs attention, or
     * the reader's count does not account for every input row.
     *
     * @throws RfqSyncBlocked
     */
    private function assertExtractionComplete(ExtractionState $state): void
    {
        $reason = match ($state->toString()) {
            ExtractionState::COMPLETE => null,
            ExtractionState::READING => 'A document is still being read. Wait for the resulting lines before syncing.',
            ExtractionState::ATTENTION => 'A document could not be read, or a re-read is offering replacement lines. Resolve it before syncing.',
            default => 'The lines do not account for every row the reader found. Check the lines against the source before syncing.',
        };
        if ($reason !== null) {
            throw new RfqSyncBlocked($reason, [['lineNumber' => null, 'lineUid' => null, 'code' => '', 'reason' => $reason]]);
        }
    }

    /**
     * Send the frozen plan, checkpointing as each step settles.
     *
     * @param array<string, mixed> $plan
     */
    private function execute(string $attemptId, array $plan, DraftActor $worker): SyncOutcome
    {
        /** @var array<string, scalar> $headerParams */
        $headerParams = is_array($plan['hdr_params'] ?? null) ? $plan['hdr_params'] : [];
        $isUpdate = ($plan['is_update'] ?? false) === true;
        $pushVersion = (string) ($plan['push_version'] ?? '0');
        /** @var list<array<string, mixed>> $items */
        $items = is_array($plan['line_items'] ?? null) ? $plan['line_items'] : [];

        // Written before the request leaves: the only thing that tells a crash inside it from one before it.
        $this->store->noteHeaderIntent($attemptId);
        try {
            $header = $this->gateway->createHeader($headerParams);
        } catch (Throwable $failure) {
            // An adapter that throws has not said no. The outcome is unknown, and unknown is never retried.
            $header = GatewayHeaderResult::unknown(self::transportFailure($failure));
        }
        if ($header->outcome === GatewayHeaderResult::ACCEPTED && trim($header->refno) === '') {
            $header = GatewayHeaderResult::unknown('4js accepted the quote header but returned no quote number.');
        }

        if ($header->outcome === GatewayHeaderResult::REJECTED) {
            $error = 'QOTHDR rejected: ' . $header->message;
            $this->store->noteHeaderFailed($attemptId, $error, true);

            return $this->finish($attemptId, SyncAttemptState::fromString(SyncAttemptState::FAILED), $plan, $worker, $this->result($plan, '', '', [], [], null), $error, '');
        }
        if ($header->outcome === GatewayHeaderResult::UNKNOWN) {
            $error = 'QOTHDR request failed: ' . $header->message;
            $this->store->noteHeaderFailed($attemptId, $error, false);
            $note = 'The quote header request did not complete, so 4js may or may not hold a quote for this draft. Do not sync again until it has been checked: look for a DRAFT quote for customer '
                . (string) ($plan['customer_id'] ?? '?') . ' raised around the time of this attempt.';

            return $this->finish($attemptId, SyncAttemptState::fromString(SyncAttemptState::UNCERTAIN), $plan, $worker, $this->result($plan, '', '', [], [], null), $error, $note);
        }

        // On an update the quote number is the one we targeted.
        $refno = $isUpdate ? (string) ($plan['existing_refno'] ?? '') : $header->refno;
        $refver = $header->refver !== '' ? $header->refver : $pushVersion;
        // The quote exists from this moment: its number is durable before any line is sent.
        $this->store->noteHeaderOk($attemptId, $refno, $refver);

        $pushed = [];
        $errors = [];
        foreach ($items as $index => $item) {
            $line = [
                'code' => (string) ($item['code'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'price' => (float) ($item['price'] ?? 0),
                'cprc' => (float) ($item['cprc'] ?? 0),
                'comment' => (string) ($item['comment'] ?? ''),
                'unitsys' => (string) ($item['unitsys'] ?? 'M'),
            ];
            try {
                $answer = $this->gateway->addLine($refno, $pushVersion, $line);
            } catch (Throwable $failure) {
                $answer = GatewayLineResult::rejected(self::transportFailure($failure));
            }
            if ($answer->accepted) {
                $detail = ['code' => $line['code'], 'quantity' => $line['quantity'], 'price' => $line['price'], 'comment' => $line['comment']];
                $pushed[] = $detail;
            } else {
                $detail = ['code' => $line['code'], 'error' => $answer->message !== '' ? $answer->message : 'rejected by 4js', 'row_number' => $item['row_number'] ?? null, 'row_id' => $item['row_id'] ?? null];
                $errors[] = $detail;
            }
            $this->store->noteLine($attemptId, $index, $answer->accepted, $detail, $index + 1);
        }

        try {
            $pdf = $this->gateway->renderPdf($refno, $refver);
        } catch (Throwable $failure) {
            $pdf = GatewayPdfResult::failed(self::transportFailure($failure));
        }

        $result = $this->result($plan, $refno, $refver, $pushed, $errors, $pdf);
        if ($errors === []) {
            return $this->finish($attemptId, SyncAttemptState::fromString(SyncAttemptState::SUCCEEDED), $plan, $worker, $result, '', '');
        }
        $first = (string) ($errors[0]['error'] ?? 'rejected by 4js');
        $message = sprintf('%d of %d line(s) were rejected by 4js (first: %s). ', count($errors), count($items), $first)
            . ($pushed !== []
                ? sprintf('%d line(s) did land on quote %s v%s; fix the rejected lines and sync again to raise a corrected version.', count($pushed), $refno, $refver)
                : 'No lines were added to the quote.');

        return $this->finish($attemptId, SyncAttemptState::fromString(SyncAttemptState::FAILED), $plan, $worker, $result + ['partial' => $pushed !== []], $message, '');
    }

    /**
     * @param array<string, mixed>                $plan
     * @param array<string, mixed>                $result
     */
    private function finish(string $attemptId, SyncAttemptState $state, array $plan, DraftActor $worker, array $result, string $error, string $note): SyncOutcome
    {
        $this->store->finishAttempt($attemptId, $state, $result, $error, $note, $worker);
        $attempt = $this->store->attempt($attemptId) ?? throw new RfqStoreUnavailable();

        return $this->outcome($attempt, $state, $plan, $result, $error);
    }

    /**
     * The result as the source returns it, kept with the attempt.
     *
     * @param array<string, mixed>       $plan
     * @param list<array<string, mixed>> $pushed
     * @param list<array<string, mixed>> $errors
     *
     * @return array<string, mixed>
     */
    private function result(array $plan, string $refno, string $refver, array $pushed, array $errors, ?GatewayPdfResult $pdf): array
    {
        return [
            'ok' => $errors === [] && $refno !== '',
            'refno' => $refno,
            'refver' => $refver,
            'is_update' => ($plan['is_update'] ?? false) === true,
            'pushed' => count($pushed),
            'pushed_items' => $pushed,
            'errors' => $errors,
            'excluded' => $plan['excluded'] ?? [],
            'fallback_margin' => $plan['fallback_margin'] ?? [],
            'pdf_url' => $pdf?->url,
            'pdf_error' => $pdf?->error,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $result
     */
    private function outcome(SyncAttemptView $attempt, SyncAttemptState $state, array $plan, array $result, string $error): SyncOutcome
    {
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $sent = is_int($result['pushed'] ?? null) ? $result['pushed'] : 0;
        $refno = trim((string) ($result['refno'] ?? ''));
        $outcome = match (true) {
            $state->is(SyncAttemptState::SUCCEEDED) => SyncOutcome::SUCCEEDED,
            $state->is(SyncAttemptState::UNCERTAIN) => SyncOutcome::UNCERTAIN,
            $sent > 0 => SyncOutcome::PARTIAL,
            default => SyncOutcome::FAILED,
        };
        $line = static fn (array $item, string $reasonKey = 'reason'): array => [
            'lineNumber' => is_int($item['row_number'] ?? null) ? $item['row_number'] : null,
            'lineUid' => is_string($item['row_id'] ?? null) ? $item['row_id'] : null,
            'code' => (string) ($item['code'] ?? ''),
            'reason' => (string) ($item[$reasonKey] ?? ''),
        ];
        $lines = is_array($plan['line_items'] ?? null) ? count($plan['line_items']) : 0;
        $isUpdate = ($result['is_update'] ?? false) === true;
        $version = (string) ($result['refver'] ?? '');
        $message = match ($outcome) {
            SyncOutcome::SUCCEEDED => sprintf('Quote %s %s - %d line(s) synced', $refno, $isUpdate ? 'updated to v' . $version : 'created', $sent),
            SyncOutcome::PARTIAL => sprintf('Quote %s %s - %d of %d line(s) synced. %s', $refno, $isUpdate ? 'updated to v' . $version : 'created', $sent, $sent + count($errors), $error),
            default => $error,
        };

        return new SyncOutcome(
            $outcome,
            $attempt,
            $message,
            $refno !== '' ? $refno : null,
            $version !== '' ? $version : null,
            $isUpdate,
            $sent,
            $lines,
            array_map(static fn (mixed $e): array => $line(is_array($e) ? $e : [], 'error'), $errors),
            array_map(static fn (mixed $e): array => $line(is_array($e) ? $e : []), is_array($plan['excluded'] ?? null) ? $plan['excluded'] : []),
            array_map(static fn (mixed $e): array => [
                'lineNumber' => is_array($e) && is_int($e['row_number'] ?? null) ? $e['row_number'] : null,
                'lineUid' => is_array($e) && is_string($e['row_id'] ?? null) ? $e['row_id'] : null,
                'code' => is_array($e) ? (string) ($e['code'] ?? '') : '',
                'margin' => is_array($e) && is_numeric($e['margin'] ?? null) ? $e['margin'] + 0 : 0,
            ], is_array($plan['fallback_margin'] ?? null) ? $plan['fallback_margin'] : []),
            is_string($result['pdf_url'] ?? null) ? $result['pdf_url'] : null,
            is_string($result['pdf_error'] ?? null) ? $result['pdf_error'] : null,
        );
    }

    /**
     * The parser's refusal as the source words it: 400 for a quote that is not
     * ready, 409 for a price the customer's rule would change.
     *
     * @param array{status: int, payload: array<string, mixed>} $problem
     */
    private static function blocked(array $problem, bool $withPricing): RfqSyncBlocked
    {
        $payload = $problem['payload'];
        $issues = [];
        foreach (['blocking_issues', 'price_changes', 'unpriced'] as $key) {
            if (!is_array($payload[$key] ?? null)) {
                continue;
            }
            foreach ($payload[$key] as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $entry = [
                    'lineNumber' => is_int($issue['row_number'] ?? null) ? $issue['row_number'] : null,
                    'lineUid' => is_string($issue['row_id'] ?? null) ? $issue['row_id'] : null,
                    'code' => (string) ($issue['code'] ?? ''),
                    'reason' => (string) ($issue['reason'] ?? ''),
                ];
                // The customer's required price is the customer's margin by another name: pricing holders only.
                if ($withPricing && is_numeric($issue['reviewed_price'] ?? null) && is_numeric($issue['required_price'] ?? null)) {
                    $entry['reviewedPrice'] = (float) $issue['reviewed_price'];
                    $entry['requiredPrice'] = (float) $issue['required_price'];
                }
                $issues[] = $entry;
            }
            break;
        }

        return new RfqSyncBlocked(
            is_string($payload['error'] ?? null) && $payload['error'] !== '' ? $payload['error'] : 'This quote cannot be sent to 4js yet. Nothing was sent.',
            $issues,
            $problem['status'] === 409,
        );
    }

    /**
     * What a failure of the transport says to the rep: its kind, never its text,
     * which can carry an address or a credential.
     */
    private static function transportFailure(Throwable $failure): string
    {
        return 'the connection to 4js failed (' . (new \ReflectionClass($failure))->getShortName() . ')';
    }

    /**
     * The customer's default margin for each product on the rows, as the pricing
     * pass looks it up. Soft: a missing customer or lookup leaves the line's own.
     *
     * @param array<string, mixed>       $details
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int|string>
     *
     * @throws RfqParserFailure
     */
    private function defaultMargins(array $details, array $rows, CorrelationId $id): array
    {
        $customerId = is_numeric($details['customer_id'] ?? null) ? (int) $details['customer_id'] : 0;
        if ($customerId < 1 || $this->margins === null) {
            return [];
        }
        $codes = [];
        foreach ($rows as $row) {
            $code = strtoupper(\TriCab\Core\Rfq\Storage\DraftLineEdits::productCode($row));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return $this->margins->forCustomer($customerId, $this->parser->productStyles($id, array_keys($codes)));
    }

    /** `next_quote_version`: at least 2, one past the current. */
    private static function nextVersion(?string $current): int
    {
        return max(2, (is_numeric($current) ? (int) $current : 1) + 1);
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) ? PythonText::strip($value) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function clean(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset($row['_draft_row_id'], $row['_system_snapshot_valid']);

            return $row;
        }, $rows);
    }
}
