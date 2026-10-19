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
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailStore;
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
 * One pass of the RFQ mail reader (RFQ plan M3 + M4, shadow mode): ask Graph what changed in the Inbox,
 * record what is new in `[mail]`, give every new message an assessment, and move the cursor on.
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
 * **Shadow only.** `live` is shadow plus a warning: no drafts, no notifications (M6).
 */
final class MailReaderPass
{
    /** The fields asked of Graph: only what is recorded or needed by the pre-filter. */
    public const SELECT = 'id,internetMessageId,conversationId,subject,from,toRecipients,ccRecipients,receivedDateTime,bodyPreview,isRead,hasAttachments,internetMessageHeaders';

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
        if ($this->options->mode === MailReaderOptions::MODE_LIVE) {
            ($this->log)('RFQ_MAIL_MODE=live: drafts are not built yet (M6), so this behaves as shadow: classify and record only.');
        }

        $result = new PassResult(PassResult::DONE);
        try {
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
        $link = $this->state->cursor();
        $deadline = $now->getTimestamp() + max(1, $this->options->passSeconds);
        $resynced = false;

        for ($page = 0; $page < max(1, $this->options->maxPages); $page++) {
            try {
                $changes = $this->source->changes($this->options->mailbox, $link, $since);
            } catch (MailboxSyncExpired $expired) {
                if ($resynced || $link === null) {
                    throw $expired;
                }
                // The token is dead (410). Start again from the time window; recording is idempotent.
                ($this->log)('Graph says the delta token expired (410); resyncing from the last ' . $this->options->firstRunDays . ' day(s).');
                $this->state->clearCursor();
                $link = null;
                $resynced = true;
                $result->resynced = true;
                $page--;
                continue;
            }

            $result->pages++;
            $result->removed += count($changes->removedIds);
            foreach ($changes->messages as $mail) {
                $result->received++;
                $this->handle($mail, $result);
            }
            // The page is done: only now does the cursor move, so a failure above redelivers it.
            $this->state->saveCursor($changes->link, $changes->complete);
            $link = $changes->link;
            if ($changes->complete || ($this->now)()->getTimestamp() >= $deadline) {
                return;
            }
        }
    }

    private function handle(IncomingMail $mail, PassResult $result): void
    {
        if (trim($mail->internetMessageId) === '') {
            ($this->log)('A message came without an internetMessageId and was not recorded.');
            $result->gone++;

            return;
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

        $key = sha1($this->options->mailbox . '|' . $mail->internetMessageId);
        $wasPending = $this->state->isPending($key);
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

        $assessment = $this->classify($mail, $recorded->threadId, $result);
        $this->recorder->recordAssessment($this->options->mailbox, $mail->internetMessageId, $assessment);
        $this->state->clearPending($key);
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
