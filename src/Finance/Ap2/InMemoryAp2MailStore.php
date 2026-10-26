<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailDrafts;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailAttachment;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailDraft;
use TriCab\Core\Finance\Ap2\Domain\Ap2SyncRun;

/** The mail, its files and the sync runs held in memory; also the email-draft view the approvals read (`add` is a test seam). */
final class InMemoryAp2MailStore implements Ap2MailStore, Ap2MailDrafts
{
    /** @var array<string, Ap2Mail> */
    private array $mail = [];

    /** @var array<string, list<Ap2MailAttachment>> */
    private array $files = [];

    /** @var array<string, Ap2SyncRun> */
    public array $runs = [];

    /** @var array<string, string> mail id => approval id, as linked */
    public array $approvals = [];

    public function add(Ap2MailDraft $draft): void
    {
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->mail[$draft->id] = new Ap2Mail(
            $draft->id,
            $now,
            $now,
            $now,
            supplierId: $draft->supplierId,
            subject: $draft->subject,
            draft: $draft->draft,
            replyTo: $draft->replyTo,
            cc: $draft->cc,
            draftAttachmentIds: $draft->attachmentIds,
        );
    }

    public function all(?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array
    {
        $rows = array_values(array_filter(
            $this->mail,
            static fn (Ap2Mail $m): bool => ($from === null || $m->receivedAt >= $from) && ($until === null || $m->receivedAt < $until),
        ));
        usort($rows, static fn (Ap2Mail $a, Ap2Mail $b): int => [$b->receivedAt, $b->id] <=> [$a->receivedAt, $a->id]);

        return $rows;
    }

    public function findMail(string $id): ?Ap2Mail
    {
        return $this->mail[$id] ?? null;
    }

    public function hasGraphMessage(string $mailbox, string $graphMessageId): bool
    {
        foreach ($this->mail as $m) {
            if (strtolower($m->mailbox) === strtolower($mailbox) && $m->graphMessageId === $graphMessageId) {
                return true;
            }
        }

        return false;
    }

    public function insertMail(Ap2Mail $mail, array $attachments): void
    {
        $this->mail[$mail->id] = $mail;
        $this->files[$mail->id] = $attachments;
    }

    public function updateMail(Ap2Mail $mail): void
    {
        $this->mail[$mail->id] = $mail;
    }

    public function attachmentsOf(string $mailId): array
    {
        return $this->files[$mailId] ?? [];
    }

    public function attachmentsOfMany(array $mailIds): array
    {
        $out = [];
        foreach ($mailIds as $id) {
            $out[$id] = $this->files[$id] ?? [];
        }

        return $out;
    }

    public function attachment(string $mailId, string $attachmentId): ?Ap2MailAttachment
    {
        foreach ($this->files[$mailId] ?? [] as $file) {
            if ($file->id === $attachmentId) {
                return $file;
            }
        }

        return null;
    }

    public function beginRun(Ap2SyncRun $run): void
    {
        $this->runs[$run->id] = $run;
    }

    public function finishRun(Ap2SyncRun $run): void
    {
        $this->runs[$run->id] = $run;
    }

    public function saveProgress(Ap2SyncRun $run): void
    {
        $current = $this->runs[$run->id] ?? null;
        if ($current !== null && $current->isRunning()) {
            $this->runs[$run->id] = $current->with(fetched: $run->fetched, created: $run->created, skipped: $run->skipped, failed: $run->failed, processed: $run->processed, error: $run->error, updatedAt: $run->updatedAt);
        }
    }

    public function findRun(string $id): ?Ap2SyncRun
    {
        return $this->runs[$id] ?? null;
    }

    public function runningRun(string $kind): ?Ap2SyncRun
    {
        $best = null;
        foreach ($this->runs as $run) {
            if ($run->kind === $kind && $run->isRunning() && ($best === null || $run->startedAt > $best->startedAt)) {
                $best = $run;
            }
        }

        return $best;
    }

    public function lastSucceededRun(string $kind): ?Ap2SyncRun
    {
        $best = null;
        foreach ($this->runs as $run) {
            if ($run->kind === $kind && $run->status === Ap2SyncRun::SUCCEEDED && ($best === null || $run->startedAt > $best->startedAt)) {
                $best = $run;
            }
        }

        return $best;
    }

    public function find(string $mailId): ?Ap2MailDraft
    {
        $m = $this->mail[$mailId] ?? null;

        return $m === null ? null : new Ap2MailDraft($m->id, $m->supplierId, $m->subject, $m->draft, $m->replyTo, $m->cc, $m->draftAttachmentIds);
    }

    public function linkApproval(string $mailId, string $approvalId): void
    {
        $this->approvals[$mailId] = $approvalId;
        if (isset($this->mail[$mailId])) {
            $this->mail[$mailId] = $this->mail[$mailId]->with(approvalId: $approvalId);
        }
    }
}
