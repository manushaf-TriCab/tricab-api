<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * One-off backfill of mail the live reader never saw (it first read only the Inbox, and staff file mail into the
 * Inbox's child folders): list each folder's messages received in the last N days with a plain filtered GET (no
 * delta, so the live reader's cursors are untouched), skip what is already recorded, and in `apply` mode record and
 * classify the rest through {@see MailReaderPass::ingest()}, the live reader's own path.
 *
 * **Shadow only.** The pass it is given has no draft automation, so no draft, link or notification is ever made,
 * whatever RFQ_MAIL_MODE says. **Graph GET only.** Dry run (the default) records and classifies nothing.
 */
final class MailBackfill
{
    public const MAX_DAYS = 30;

    /** Stop after this many throttled-out classifications in a row: the rest is better re-run later. */
    private const MAX_GIVE_UPS = 3;

    /** Stop after this many failures in a row (Graph or database down). */
    private const MAX_CONSECUTIVE_ERRORS = 5;

    /** @var Closure(string): void */
    private readonly Closure $out;

    /**
     * @param (callable(string): void)|null $out
     */
    public function __construct(
        private readonly string $mailbox,
        private readonly MailBackfillSource $source,
        private readonly MailReaderPass $pass,
        private readonly ?PacedMailClassifier $classifier = null,
        ?callable $out = null,
    ) {
        $this->out = $out === null ? static function (string $line): void {
        } : Closure::fromCallable($out);
    }

    /**
     * @param int $limit stop after this many not-yet-recorded messages have been handled (apply only; 0 = no limit)
     * @return list<array{folder: string, seen: int, alreadyRecorded: int, new: int, recorded: int, classified: int, skipped: int, deferred: int, errors: int}>
     */
    public function run(DateTimeImmutable $now, int $days, bool $includeDeleted, bool $apply, int $limit = 0): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $since = $now->sub(new DateInterval('P' . $days . 'D'));
        $summary = [];
        $handled = 0;
        $stopped = false;

        foreach ($this->source->backfillFolders($this->mailbox, $includeDeleted) as $folder) {
            $row = ['folder' => $folder->path, 'seen' => 0, 'alreadyRecorded' => 0, 'new' => 0, 'recorded' => 0, 'classified' => 0, 'skipped' => 0, 'deferred' => 0, 'errors' => 0];
            $consecutiveErrors = 0;
            $link = null;

            while (true) {
                $page = $this->source->listPage($this->mailbox, $folder, $link, $since);
                foreach ($page->messages as $mail) {
                    $row['seen']++;
                    if (trim($mail->internetMessageId) === '') {
                        $row['errors']++;
                        continue;
                    }
                    if ($this->pass->isRecorded($mail)) {
                        $row['alreadyRecorded']++;
                        continue;
                    }
                    $row['new']++;
                    if (!$apply) {
                        continue;
                    }
                    if ($limit > 0 && $handled >= $limit) {
                        // Counted as new above; left for a later run.
                        $stopped = true;
                        break;
                    }
                    $handled++;
                    $result = new PassResult(PassResult::DONE);
                    try {
                        $this->pass->ingest($mail, $result, true);
                        $consecutiveErrors = 0;
                    } catch (Throwable $failure) {
                        $row['errors']++;
                        $consecutiveErrors++;
                        ($this->out)('  error on one message in "' . $folder->path . '": ' . $failure::class . ': ' . mb_substr($failure->getMessage(), 0, 200));
                    }
                    $row['recorded'] += $result->recorded;
                    $row['classified'] += $result->classified;
                    $row['skipped'] += $result->skipped;
                    $row['deferred'] += $result->deferred;
                    $row['errors'] += $result->uncertainFailures + $result->gone;
                    if ($consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                        ($this->out)('Stopping: ' . self::MAX_CONSECUTIVE_ERRORS . ' failures in a row. Fix the cause and run it again; recorded messages are skipped.');
                        $stopped = true;
                        break;
                    }
                    if ($this->classifier !== null && $this->classifier->consecutiveGiveUps >= self::MAX_GIVE_UPS) {
                        ($this->out)('Stopping: the model kept throttling (429). Wait a few minutes and run it again; recorded messages are skipped.');
                        $stopped = true;
                        break;
                    }
                }
                if ($stopped || $page->complete) {
                    break;
                }
                $link = $page->link;
            }

            $summary[] = $row;
            ($this->out)(self::line($row, $apply));
            if ($stopped) {
                break;
            }
        }

        return $summary;
    }

    /** @param array{folder: string, seen: int, alreadyRecorded: int, new: int, recorded: int, classified: int, skipped: int, deferred: int, errors: int} $row */
    private static function line(array $row, bool $apply): string
    {
        $line = sprintf('%-45s seen %4d   already recorded %4d   new %4d', $row['folder'], $row['seen'], $row['alreadyRecorded'], $row['new']);
        if ($apply) {
            $line .= sprintf('   recorded now %4d   classified %4d   pre-filter skipped %3d   deferred %3d   errors %3d', $row['recorded'], $row['classified'], $row['skipped'], $row['deferred'], $row['errors']);
        }

        return $line;
    }
}
