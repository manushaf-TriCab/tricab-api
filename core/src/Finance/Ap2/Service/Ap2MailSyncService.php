<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateInterval;
use DateTimeImmutable;
use Throwable;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2MailClassification;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailClassifier;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailAttachment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2SyncRun;
use TriCab\Core\Finance\Ap2\Domain\Ap2Upstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;

/**
 * `POST /mail/sync`: copies new messages of the AP mailbox's Inbox into `ap2_mail`. READ ONLY toward the mailbox: it
 * lists, reads and downloads attachments through the shared {@see FinanceMailboxReader} (application key AP2, which
 * audits every read) and never moves, flags or marks anything.
 *
 *  - Window: `from`/`to` are Sydney calendar days (at most 31 days, at most 500 messages, the shared reader's rules);
 *    without them it reads from a day before the last successful run (or 7 days back the first time) up to now.
 *  - Dedupe: a message already stored for the mailbox (its Graph immutable id) is skipped.
 *  - Per new message: the body as plain text; attachments (not inline images) into `finance.attachments`; the
 *    supplier by sender address or domain ({@see Ap2SupplierMatcher}), then the classifier for the category, a
 *    summary and (only if the address matched nobody) a supplier; one history line when a supplier is known.
 *  - A message that cannot be read is counted as skipped and the run goes on; a refused or failed LISTING fails the run.
 *  - Every run is recorded in `ap2_sync_runs`. The answer is `{fetched, created, skipped, truncated}`.
 */
final class Ap2MailSyncService
{
    public const KIND = 'mail';

    public const DEFAULT_DAYS = 7;

    public const MAX_ATTACHMENTS = 20;

    public const MAX_ATTACHMENT_BYTES = 20971520;

    public function __construct(
        private readonly Ap2MailStore $mail,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2HistoryStore $history,
        private readonly FinanceMailboxReader $reader,
        private readonly Ap2FileService $files,
        private readonly Ap2MailClassifier $classifier,
        private readonly Ap2Transactions $transactions,
        private readonly ApTime $time,
        private readonly string $mailbox,
    ) {
    }

    /**
     * @param array<string, mixed> $body `{from?, to?}`
     * @return array{fetched: int, created: int, skipped: int, truncated: bool}
     */
    public function sync(?Ap2Actor $actor, array $body): array
    {
        $input = new Ap2Input($body);
        $input->date('from');
        $input->date('to');
        if ($input->issues() !== []) {
            throw new Ap2Invalid('The window is invalid', Ap2Failure::details($input->issues()));
        }
        $values = $input->values();
        $fromDay = is_string($values['from'] ?? null) ? $values['from'] : null;
        $toDay = is_string($values['to'] ?? null) ? $values['to'] : null;
        if ($fromDay !== null && $toDay !== null && $fromDay > $toDay) {
            throw new Ap2Invalid('The window is invalid', Ap2Failure::details(['from must not be after to']));
        }

        $now = $this->time->now();
        [$start, $end] = $this->window($fromDay, $toDay, $now);
        $userId = $actor?->userId;

        $run = new Ap2SyncRun(bin2hex(random_bytes(16)), self::KIND, Ap2SyncRun::RUNNING, $now, $start->format('Y-m-d'), $end->format('Y-m-d'), startedByUserId: $userId);
        $this->mail->beginRun($run);

        $fetched = $created = $skipped = 0;
        $truncated = false;
        $errors = [];
        try {
            $summaries = $this->reader->listMessages(Module::APP_AP2, $this->mailbox, $start, $end, 500, $userId, 'inbox');
            $fetched = count($summaries);
            $truncated = $fetched >= 500;
            $suppliers = $this->suppliers->all();
            foreach ($summaries as $summary) {
                try {
                    if ($this->process($summary, $suppliers, $userId)) {
                        ++$created;
                    } else {
                        ++$skipped;
                    }
                } catch (FinanceMailRefused $refused) {
                    throw $refused;
                } catch (MailboxReadFailed | FinanceAttachmentException $failure) {
                    ++$skipped;
                    $errors[] = $failure->getMessage();
                }
            }
        } catch (Throwable $failure) {
            $this->mail->finishRun($run->with(status: Ap2SyncRun::FAILED, fetched: $fetched, created: $created, skipped: $skipped, error: mb_substr($failure->getMessage(), 0, 1000), finishedAt: $this->time->now()));

            throw match (true) {
                $failure instanceof FinanceMailRefused => new Ap2Conflict('Tri-AP2 may not read ' . $this->mailbox, Ap2Failure::details([$failure->getMessage()]), $failure),
                $failure instanceof FinanceMailInvalid => new Ap2Invalid($failure->getMessage(), Ap2Failure::details([$failure->getMessage()]), $failure),
                $failure instanceof MailboxReadFailed => new Ap2Upstream('The mailbox could not be read', Ap2Failure::details([$failure->getMessage()]), $failure),
                default => $failure,
            };
        }

        $this->mail->finishRun($run->with(
            status: Ap2SyncRun::SUCCEEDED,
            fetched: $fetched,
            created: $created,
            skipped: $skipped,
            error: $errors === [] ? null : mb_substr(count($errors) . ' message(s) could not be read: ' . $errors[0], 0, 1000),
            finishedAt: $this->time->now(),
        ));

        return ['fetched' => $fetched, 'created' => $created, 'skipped' => $skipped, 'truncated' => $truncated];
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private function window(?string $fromDay, ?string $toDay, DateTimeImmutable $now): array
    {
        [$dayStart, $dayEnd] = $this->time->dayBounds($fromDay, $toDay);
        $end = $dayEnd === null ? $now : min($now, $dayEnd->sub(new DateInterval('PT1S')));
        if ($dayStart !== null) {
            $start = $dayStart;
        } else {
            $last = $this->mail->lastSucceededRun(self::KIND);
            $start = $last === null ? $end->sub(new DateInterval('P' . self::DEFAULT_DAYS . 'D')) : $last->startedAt->sub(new DateInterval('P1D'));
            // Never ask for more than the reader's 31 days.
            $floor = $end->sub(new DateInterval('P31D'))->add(new DateInterval('PT1H'));
            $start = max($start, $floor);
        }
        if ($start > $end) {
            throw new Ap2Invalid('The window is invalid', Ap2Failure::details(['from must not be after to']));
        }

        return [$start, $end];
    }

    /**
     * @param list<\TriCab\Core\Finance\Ap2\Domain\Ap2Supplier> $suppliers
     * @return bool true when a new row was stored, false when the message was already known
     */
    private function process(MailboxMessageSummary $summary, array $suppliers, ?int $userId): bool
    {
        if ($this->mail->hasGraphMessage($this->mailbox, $summary->id)) {
            return false;
        }
        $message = $this->reader->getMessage(Module::APP_AP2, $this->mailbox, $summary->id, $userId);
        $files = $this->readAttachments($summary, $userId);

        $text = self::text($message);
        $names = array_map(static fn (array $f): string => $f['name'], $files);
        $matched = Ap2SupplierMatcher::match($summary->fromAddress, $suppliers);
        $classification = $this->classify($summary, $text, $names, $suppliers);
        $known = array_map(static fn ($s): string => $s->id, $suppliers);
        $supplierId = $matched ?? ($classification->supplierId !== null && in_array($classification->supplierId, $known, true) ? $classification->supplierId : null);
        $category = $classification->category !== null && in_array($classification->category, Ap2Mail::CATEGORIES, true) ? $classification->category : 'Other';

        $now = $this->time->now();
        $mailId = bin2hex(random_bytes(16));
        $from = strtolower(trim($summary->fromAddress));
        $stored = [];
        $rows = [];
        foreach ($files as $f) {
            $file = $this->files->store($f['name'], $f['type'], $f['bytes'], $userId);
            $stored[] = $file->id;
            $rows[] = new Ap2MailAttachment(bin2hex(random_bytes(16)), $mailId, $file->id, $f['name'], strlen($f['bytes']), $file->contentType, $now, $f['graphId']);
        }
        $mail = new Ap2Mail(
            $mailId,
            $summary->receivedAt->setTimezone(new \DateTimeZone('UTC')),
            $now,
            $now,
            supplierId: $supplierId,
            mailbox: $this->mailbox,
            graphMessageId: $summary->id,
            subject: mb_substr(trim($summary->subject), 0, 400),
            bodyPreview: mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 300),
            body: $text,
            category: $category,
            from: $from,
            cc: self::copies($message),
            replyTo: $from,
            aiSummary: $classification->summary === null ? null : mb_substr($classification->summary, 0, 1000),
            aiConfidence: $classification->confidence > 0.0 ? max(0.0, min(1.0, $classification->confidence)) : null,
        );

        $this->transactions->run(function () use ($mail, $rows, $now, $supplierId): void {
            $this->mail->insertMail($mail, $rows);
            if ($supplierId !== null) {
                $this->history->append(new Ap2HistoryEntry(
                    bin2hex(random_bytes(16)),
                    $supplierId,
                    'Email received',
                    $mail->subject . ' · ' . $mail->from . ' · ' . $mail->category,
                    new Ap2Person(0, 'Tri-AP2 mail sync', ''),
                    $now,
                    null,
                    $mail->id,
                    ['category' => $mail->category],
                ));
            }
        });
        foreach ($stored as $id) {
            $this->files->attachToMail($id, $mailId, $userId);
        }

        return true;
    }

    /** @return list<array{name: string, type: string, bytes: string, graphId: string}> */
    private function readAttachments(MailboxMessageSummary $summary, ?int $userId): array
    {
        if (!$summary->hasAttachments) {
            return [];
        }
        $out = [];
        foreach ($this->reader->listAttachments(Module::APP_AP2, $this->mailbox, $summary->id, $userId) as $listed) {
            if ($listed->isInline || $listed->size > self::MAX_ATTACHMENT_BYTES || count($out) >= self::MAX_ATTACHMENTS) {
                continue;
            }
            $file = $this->reader->getAttachment(Module::APP_AP2, $this->mailbox, $summary->id, $listed->id, $userId);
            $out[] = ['name' => $listed->name, 'type' => $listed->contentType, 'bytes' => $file->content, 'graphId' => $listed->id];
        }

        return $out;
    }

    /**
     * @param list<string> $names
     * @param list<\TriCab\Core\Finance\Ap2\Domain\Ap2Supplier> $suppliers
     */
    private function classify(MailboxMessageSummary $summary, string $text, array $names, array $suppliers): Ap2MailClassification
    {
        try {
            return $this->classifier->classify($summary->subject, $summary->fromAddress, $text, $names, Ap2SupplierMatcher::refs($suppliers));
        } catch (Ap2AiUnavailable) {
            return Ap2MailClassification::none();
        }
    }

    /** The original Cc list, less our own mailbox, as a `;`-separated line. */
    private function copies(MailboxMessage $message): string
    {
        $out = [];
        foreach ($message->cc as $recipient) {
            $a = Ap2SupplierMatcher::address($recipient->address);
            if ($a !== '' && $a !== strtolower($this->mailbox) && !in_array($a, $out, true)) {
                $out[] = $a;
            }
        }

        return mb_substr(implode('; ', $out), 0, 1000);
    }

    /** The message as plain text: HTML is flattened (scripts and styles dropped, line breaks kept). */
    public static function text(MailboxMessage $message): string
    {
        $body = $message->body;
        if ($message->bodyType === MailboxMessage::BODY_HTML) {
            $body = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', '', $body) ?? $body;
            $body = preg_replace('~<\s*br\s*/?>|</\s*(p|div|tr|li|h[1-6]|table)\s*>~i', "\n", $body) ?? $body;
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $body = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $body);
        $body = preg_replace('/[ \t]+/', ' ', $body) ?? $body;
        $body = preg_replace('/ ?\n ?/', "\n", $body) ?? $body;
        $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;

        return mb_substr(trim($body), 0, 200000);
    }
}
