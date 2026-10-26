<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailAttachment;
use TriCab\Core\Finance\Ap2\Domain\Ap2SyncRun;

/** `finance.ap2_mail`, `finance.ap2_mail_attachments` and `finance.ap2_sync_runs`. */
interface Ap2MailStore
{
    /**
     * Newest received first. `$from` is inclusive and `$until` exclusive, both UTC instants.
     *
     * @return list<Ap2Mail>
     */
    public function all(?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array;

    public function findMail(string $id): ?Ap2Mail;

    /** Is this message of this mailbox already stored (the sync's dedupe)? */
    public function hasGraphMessage(string $mailbox, string $graphMessageId): bool;

    /** @param list<Ap2MailAttachment> $attachments */
    public function insertMail(Ap2Mail $mail, array $attachments): void;

    public function updateMail(Ap2Mail $mail): void;

    /** @return list<Ap2MailAttachment> */
    public function attachmentsOf(string $mailId): array;

    /**
     * @param list<string> $mailIds
     * @return array<string, list<Ap2MailAttachment>> keyed by mail id
     */
    public function attachmentsOfMany(array $mailIds): array;

    public function attachment(string $mailId, string $attachmentId): ?Ap2MailAttachment;

    public function beginRun(Ap2SyncRun $run): void;

    /** Writes the run's mutable columns (status, counters, error, progress time, finish time). */
    public function finishRun(Ap2SyncRun $run): void;

    /** Writes only the counters, the error and the progress time, and only while the run is still Running (a Stop must stick). */
    public function saveProgress(Ap2SyncRun $run): void;

    public function findRun(string $id): ?Ap2SyncRun;

    /** The newest run of that kind still marked Running (stale or not), or null. */
    public function runningRun(string $kind): ?Ap2SyncRun;

    /** The newest run of that kind that succeeded, or null. */
    public function lastSucceededRun(string $kind): ?Ap2SyncRun;
}
