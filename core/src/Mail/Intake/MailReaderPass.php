<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Throwable;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Application\DTO\NewMessage;
use TriCab\Core\Mail\Application\Service\MailDraftAutomation;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailVocabulary;
use TriCab\Core\Shared\Mailbox\MailboxAccessDenied;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;
use TriCab\Core\Shared\Mailbox\MailboxSyncExpired;

/**
 * One pass of the RFQ mail reader (RFQ plan M3 + M4, shadow mode): ask Graph what changed in the Inbox and the
 * folders under it (staff file mail into those by hand), record what is new in `[mail]`, give every new message an assessment, and move the cursor on.
 *
 * **The database is touched only when Graph returns messages** (plus a heartbeat and state changes), so
 * the serverless `tc` can pause between mail. Graph failures propagate as the `Mailbox*` exceptions and a
 * database that is resuming as whatever the store throws: in both cases the cursor stays where the last
 * finished page left it, so nothing is lost and the next pass redelivers.
 *
 * **Safe to repeat.** Graph redelivers (read-flag changes, a retried pass, a 410 resync). The recorder
 * dedupes on internetMessageId, and only a message that was newly recorded, or one a dead pass recorded
 * without assessing (the `pending` marker), is assessed.
 *
 * **Shadow unless drafts are on.** Drafts (M6) take a {@see MailDraftAutomation}, which the caller passes only
 * when `RFQ_MAIL_MODE=live` and `RFQ_MAIL_DRAFTS_ENABLED=true`. Without one this pass is exactly the shadow
 * pass (`live` alone adds a log warning): no draft, no link, no extra database call, no notification.
 * With one, a message labelled `rfq` or `rfq_followup` is written to the state's retry list *before* its draft
 * or link is attempted and removed when that succeeds; a failure leaves it there and each later pass tries
 * again with a back-off, up to {@see self::DRAFT_ATTEMPTS} times.
 */
final class MailReaderPass
{
    /** The fields asked of Graph: only what is recorded or needed by the pre-filter. */
    public const SELECT = 'id,internetMessageId,conversationId,subject,from,toRecipients,ccRecipients,receivedDateTime,bodyPreview,isRead,hasAttachments,internetMessageHeaders';

    /** How many times a draft is tried for one message before the reader stops and leaves it to a person. */
    public const DRAFT_ATTEMPTS = 8;

    /** @var Closure(string): void */
    private readonly Closure $log;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    private readonly MailPrefilter $prefilter;

    /**
     * @param list<string> $ownAddresses
     * @param (callable(string): void)|null $log
     * @param (callable(): DateTimeImmutable)|null $now
     */
    public function __construct(
        private readonly MailReaderOptions $options,
        private readonly MailDeltaSource $source,
        private readonly MailboxReader $reader,
        private readonly MailIntakeRecorder $recorder,
        private readonly MailStore $store,
        private readonly MailClassifier $classifier,
        private readonly MailReaderState $state,
        array $ownAddresses = [],
        ?callable $log = null,
        ?callable $now = null,
        private readonly ?MailDraftAutomation $drafts = null,
    ) {
        $this->log = $log === null ? static function (string $line): void {
        } : Closure::fromCallable($log);
        $this->now = $now === null ? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new \DateTimeZone('UTC')) : Closure::fromCallable($now);
        $this->prefilter = new MailPrefilter($options->mailbox, $ownAddresses);
    }

    public function run(): PassResult
    {
        $now = ($this->now)();

        if ($this->options->mode === MailReaderOptions::MODE_OFF) {
            return new PassResult(PassResult::OFF);
        }
        if (!$this->options->readEnabled) {
            // Say so once, and only if the mailbox was ever shown as connected: a reader that was never
            // switched on must not touch a database that may not have the schema yet.
            $connection = $this->state->connection();
            if ($connection !== null && $connection['connection'] !== 'paused') {
                $this->recorder->recordSync($this->options->mailbox, 'paused', $now);
                $this->state->saveConnection('paused', $now);
            }

            return new PassResult(PassResult::PAUSED);
        }
        if ($this->options->mode === MailReaderOptions::MODE_LIVE && $this->drafts === null) {
            ($this->log)('RFQ_MAIL_MODE=live but RFQ_MAIL_DRAFTS_ENABLED is not true, so this behaves as shadow: classify and record only.');
        }

        $result = new PassResult(PassResult::DONE);
        $result->draftsOn = $this->drafts !== null;
        try {
            $this->retryDrafts($result, $now);
            $this->readPages($result, $now);
        } catch (Throwable $failure) {
            $this->noteFailure($failure, $now);
            throw $failure;
        }

        $connection = $this->state->connection();
        $due = $connection === null
            || $connection['connection'] !== 'connected'
            || ($now->getTimestamp() - $connection['at']->getTimestamp()) >= $this->options->heartbeatMinutes * 60;
        if ($result->touchedDatabase || $due) {
            $this->recorder->recordSync($this->options->mailbox, 'connected', ($this->now)());
            $this->state->saveConnection('connected', ($this->now)());
            $result->touchedDatabase = true;
        }

        return $result;
    }

    private function readPages(PassResult $result, DateTimeImmutable $now): void
    {
        $since = $now->sub(new DateInterval('P' . max(1, $this->options->firstRunDays) . 'D'));
        $deadline = $now->getTimestamp() + max(1, $this->options->passSeconds);
        $pagesLeft = max(1, $this->options->maxPages);

        $folders = $this->folders($now);
        foreach ($folders as $index => $folder) {
            // The time and page budget is shared. The Inbox always gets its turn; a later folder waits for the
            // next pass when the budget is spent (its own cursor is unchanged, so nothing is lost).
            if ($index > 0 && ($pagesLeft <= 0 || ($this->now)()->getTimestamp() >= $deadline)) {
                return;
            }
            $result->folders++;
            $this->readFolder($folder, $since, $deadline, $pagesLeft, $result);
        }
    }

    /**
     * The Inbox and the folders below it, from the state's copy while it is fresh. A listing that fails does not
     * stop the Inbox being read: the last known list (or the Inbox alone) is used and the listing is retried next pass.
     *
     * @return list<MailFolder>
     */
    private function folders(DateTimeImmutable $now): array
    {
        $known = $this->state->folders();
        $fresh = $known !== null && ($now->getTimestamp() - $known['at']->getTimestamp()) < max(1, $this->options->folderRefreshMinutes) * 60;
        if ($fresh) {
            $folders = $known['folders'];
        } else {
            try {
                $folders = $this->source->folders($this->options->mailbox);
                $this->state->saveFolders($folders, $now);
            } catch (MailboxReadFailed | MailboxReadUnavailable | MailboxAccessDenied | MailboxItemNotFound $failure) {
                ($this->log)('Could not list the Inbox folders (' . $failure->getMessage() . '); using the last known list.');
                $folders = $known['folders'] ?? [];
            }
        }

        $wanted = [MailFolder::inbox()];
        $seen = [MailFolder::INBOX => true];
        $excluded = array_map('strtolower', $this->options->excludeFolders);
        foreach ($folders as $folder) {
            if ($folder->isInbox() || isset($seen[$folder->id]) || $folder->isExcluded($excluded)) {
                continue;
            }
            $seen[$folder->id] = true;
            $wanted[] = $folder;
        }

        return $wanted;
    }

    private function readFolder(MailFolder $folder, DateTimeImmutable $since, int $deadline, int &$pagesLeft, PassResult $result): void
    {
        $link = $this->state->cursor($folder->id);
        $resynced = false;

        while ($pagesLeft > 0) {
            try {
                $changes = $this->source->changes($this->options->mailbox, $link, $since, $folder->id);
            } catch (MailboxSyncExpired $expired) {
                if ($resynced || $link === null) {
                    throw $expired;
                }
                // The token is dead (410). Start again from the time window; recording is idempotent.
                ($this->log)('Graph says the delta token for "' . $folder->path . '" expired (410); resyncing from the last ' . $this->options->firstRunDays . ' day(s).');
                $this->state->clearCursor($folder->id);
                $link = null;
                $resynced = true;
                $result->resynced = true;
                continue;
            } catch (MailboxItemNotFound $gone) {
                if ($folder->isInbox()) {
                    throw $gone;
                }
                // The folder was deleted or moved since it was listed: drop its cursor and list the folders again next pass.
                ($this->log)('The folder "' . $folder->path . '" is gone; its cursor is dropped and the folders are listed again.');
                $this->state->clearCursor($folder->id);
                $this->state->clearFolders();

                return;
            }

            $pagesLeft--;
            $result->pages++;
            // A removal is Graph saying the message left THIS folder (filed into another one, or deleted). Nothing
            // recorded is touched: the message was recorded when it arrived, and a moved one reappears in its new
            // folder's delta, where its internetMessageId finds the record already there.
            $result->removed += count($changes->removedIds);
            foreach ($changes->messages as $mail) {
                $result->received++;
                $this->ingest($mail, $result);
            }
            // The page is done: only now does the cursor move, so a failure above redelivers it.
            $this->state->saveCursor($changes->link, $changes->complete, $folder->id);
            $link = $changes->link;
            if ($changes->complete || ($this->now)()->getTimestamp() >= $deadline) {
                return;
            }
        }
    }

    /** Whether this message (by mailbox and internetMessageId, never by Graph id) is already in `[mail]`. */
    public function isRecorded(IncomingMail $mail): bool
    {
        if (trim($mail->internetMessageId) === '') {
            return false;
        }
        $mailbox = strtolower(trim($this->options->mailbox));

        return $this->store->findMessageId($this->store->ensureMailbox($mailbox, $mailbox), $mail->internetMessageId) !== null;
    }

    /**
     * Records one message and gives it an assessment. Public so the backfill runs exactly the live path.
     * Idempotent: a message already recorded (the same internetMessageId in this mailbox, whichever folder it
     * is now in) is counted as a duplicate and nothing more happens, unless a dead pass left it unassessed.
     * With `$deferThrottled` (backfill) a message whose classification is still throttled after retries is not recorded.
     */
    public function ingest(IncomingMail $mail, PassResult $result, bool $deferThrottled = false): void
    {
        $this->handle($mail, $result, $deferThrottled);
    }

    private function handle(IncomingMail $mail, PassResult $result, bool $deferThrottled = false): void
    {
        if (trim($mail->internetMessageId) === '') {
            ($this->log)('A message came without an internetMessageId and was not recorded.');
            $result->gone++;

            return;
        }

        $key = sha1($this->options->mailbox . '|' . $mail->internetMessageId);
        $wasPending = $this->state->isPending($key);
        if (!$wasPending) {
            // Already recorded (redelivered, or filed into another folder, which Graph reports as a new row there):
            // nothing to fetch or classify. A message a dead pass left unassessed is still marked pending, so it
            // does not stop here.
            $result->touchedDatabase = true;
            if ($this->isRecorded($mail)) {
                $result->duplicates++;

                return;
            }
        }

        // Everything that needs only Graph comes first, so a Graph failure leaves nothing half-done.
        try {
            if ($mail->hasAttachments) {
                $mail = $mail->withAttachments(array_map(
                    static fn (MailboxAttachmentSummary $a): NewAttachment => new NewAttachment($a->id, $a->name, $a->contentType, $a->size, $a->isInline),
                    $this->reader->listAttachments($this->options->mailbox, $mail->graphMessageId),
                ));
            }
            if ($mail->headers === null) {
                $mail = $mail->withHeaders($this->source->headers($this->options->mailbox, $mail->graphMessageId));
            }
        } catch (MailboxItemNotFound) {
            // Moved or deleted since the delta row: nothing to record.
            $result->gone++;

            return;
        }
        $skip = $this->prefilter->check($mail);

        // Backfill: classify BEFORE anything is recorded, so a message the model is still throttling on is left
        // completely alone (no record, no pending marker) and a later run picks it up as new. The conversation-has-draft
        // hint is false here (no thread exists yet; the backfill makes no drafts).
        $assessment = null;
        if ($deferThrottled && $skip === null) {
            try {
                $assessment = $this->classify($mail, '', $result);
            } catch (ClassifierThrottled) {
                $result->deferred++;

                return;
            }
        }

        $this->state->markPending($key);
        $result->touchedDatabase = true;
        $recorded = $this->recorder->recordMessage(new NewMessage(
            $this->options->mailbox,
            $mail->graphMessageId,
            $mail->internetMessageId,
            $mail->conversationId,
            $mail->subject,
            $mail->from,
            $mail->to,
            $mail->cc,
            $mail->receivedAt,
            $mail->bodyPreview,
            $mail->isRead,
            $mail->attachments,
        ));
        if (!$recorded->created) {
            $result->duplicates++;
            if (!$wasPending) {
                $this->state->clearPending($key);

                return;
            }
            // A pass died after recording this message and before assessing it: finish the job.
        } else {
            $result->recorded++;
        }

        if ($skip !== null) {
            $this->recorder->recordAssessment($this->options->mailbox, $mail->internetMessageId, NewAssessment::skipped($skip->reason, 'prefilter-v1', ($this->now)()));
            $result->skipped++;
            $this->state->clearPending($key);

            return;
        }

        $assessment ??= $this->classify($mail, $recorded->threadId, $result);
        $this->recorder->recordAssessment($this->options->mailbox, $mail->internetMessageId, $assessment);
        if ($this->drafts !== null && !$assessment->skipped
            && in_array($assessment->label, [MailVocabulary::LABEL_RFQ, MailVocabulary::LABEL_RFQ_FOLLOWUP], true)) {
            // Written before the attempt: the message is assessed now, Graph will not bring it back, and
            // `pending` is cleared below, so this entry is all that remembers the draft is owed.
            $entry = ['threadId' => $recorded->threadId, 'messageId' => $recorded->messageId, 'label' => $assessment->label, 'injection' => $assessment->injectionSuspected, 'attempts' => 0, 'nextAt' => 0];
            $this->state->saveDraftRetry($key, $entry);
            $this->attemptDraft($key, $entry, $result);
        }
        $this->state->clearPending($key);
    }

    /** Tries the drafts a failed attempt left owed (drafts on only; nothing is read unless the state lists some). */
    private function retryDrafts(PassResult $result, DateTimeImmutable $now): void
    {
        if ($this->drafts === null) {
            return;
        }
        foreach ($this->state->draftRetries() as $key => $entry) {
            if ($entry['nextAt'] > $now->getTimestamp()) {
                continue;
            }
            $result->touchedDatabase = true;
            $result->draftRetries++;
            $this->attemptDraft($key, $entry, $result);
        }
    }

    /**
     * @param array{threadId: string, messageId: string, label: string, injection: bool, attempts: int, nextAt: int} $entry
     */
    private function attemptDraft(string $key, array $entry, PassResult $result): void
    {
        if ($this->drafts === null) {
            return;
        }
        try {
            $outcome = $this->drafts->apply($entry['threadId'], $entry['messageId'], $entry['label'], $entry['injection']);
        } catch (MailUnavailable $failure) {
            $result->draftFailures++;
            $attempts = $entry['attempts'] + 1;
            if ($attempts >= self::DRAFT_ATTEMPTS) {
                ($this->log)('Gave up making a draft after ' . $attempts . ' tries (' . $failure->getMessage() . '). The conversation stays in the inbox for a person.');
                $this->state->clearDraftRetry($key);

                return;
            }
            ($this->log)('Draft not made yet (try ' . $attempts . '): ' . $failure->getMessage());
            $entry['attempts'] = $attempts;
            $entry['nextAt'] = ($this->now)()->getTimestamp() + min(3600, 60 * (2 ** $attempts));
            $this->state->saveDraftRetry($key, $entry);

            return;
        }
        if ($outcome === MailDraftAutomation::CREATED) {
            $result->drafted++;
        } elseif ($outcome === MailDraftAutomation::FOLLOWUP) {
            $result->followups++;
        }
        $this->state->clearDraftRetry($key);
    }

    private function classify(IncomingMail $mail, string $threadId, PassResult $result): NewAssessment
    {
        $model = $this->classifier->modelName();
        $version = $this->classifier->promptVersion();
        try {
            $input = new ClassifierInput(
                $mail->subject,
                $mail->from->name,
                $mail->from->address,
                $this->bodyText($mail),
                array_map(
                    static fn (NewAttachment $a): array => ['name' => $a->fileName, 'type' => $a->mimeType],
                    array_values(array_filter($mail->attachments, static fn (NewAttachment $a): bool => !$a->isInline)),
                ),
                $this->conversationHasDraft($threadId),
            );
            $verdict = $this->classifier->classify($input);
            $result->classified++;

            return NewAssessment::classified(
                $verdict->label,
                $verdict->confidence,
                $verdict->reason,
                $verdict->model,
                $verdict->version,
                ($this->now)(),
                $verdict->latencyMs,
                $verdict->injectionSuspected,
            );
        } catch (ClassifierFailed $failure) {
            $result->uncertainFailures++;
            ($this->log)('Classifier failed: ' . $failure->getMessage());

            return NewAssessment::classified(
                MailVocabulary::LABEL_UNCERTAIN,
                0.0,
                'Not classified: ' . $failure->getMessage(),
                $failure->model !== '' ? $failure->model : $model,
                $failure->version !== '' ? $failure->version : $version,
                ($this->now)(),
                $failure->latencyMs > 0 ? $failure->latencyMs : null,
            );
        } catch (MailboxItemNotFound) {
            $result->uncertainFailures++;

            return NewAssessment::classified(MailVocabulary::LABEL_UNCERTAIN, 0.0, 'Not classified: the message left the mailbox before its body could be read.', $model, $version, ($this->now)());
        }
    }

    /** Fetched for this message only, trimmed, and never stored. */
    private function bodyText(IncomingMail $mail): string
    {
        $body = $this->reader->getMessage($this->options->mailbox, $mail->graphMessageId);
        $text = $body->bodyType === MailboxMessage::BODY_HTML ? HtmlToText::convert($body->body) : HtmlToText::tidy($body->body);

        return mb_substr($text, 0, $this->options->bodyChars);
    }

    private function conversationHasDraft(string $threadId): bool
    {
        $thread = $this->store->thread($threadId);

        return $thread !== null && $thread->links !== [];
    }

    private function noteFailure(Throwable $failure, DateTimeImmutable $now): void
    {
        $connection = $this->state->connection();
        // Only a Graph failure is the mailbox's connection going wrong; a database failure is not.
        $graphFailure = $failure instanceof MailboxReadFailed || $failure instanceof MailboxReadUnavailable || $failure instanceof MailboxAccessDenied;
        if (!$graphFailure || ($connection['connection'] ?? '') === 'error') {
            return;
        }
        try {
            $this->recorder->recordSync($this->options->mailbox, 'error', $now);
            $this->state->saveConnection('error', $now);
        } catch (Throwable) {
            // The database may be the thing that is down; the original failure is the one to report.
        }
    }
}
