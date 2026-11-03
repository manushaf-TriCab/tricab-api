<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;
use TriCab\Core\Rfq\Storage\StoredReaderJob;
use TriCab\Core\Rfq\Storage\StoreFormat;
use TriCab\Core\Rfq\Storage\StoreProjection;

/**
 * One background read of one document, and what it produced. Contract
 * `rfq.reader-result.v1`.
 *
 * `baseRevision` is the revision the read started from. If the RFQ was saved
 * since, the rows are a proposal rather than a replacement — a stale worker
 * cannot overwrite a rep who saved first (`test_reader_jobs`).
 *
 * `note` is written for the rep ("could not read this file: …"), never a stack
 * trace. `reader` names the engine and version that produced the rows, which
 * is recorded with results (plan 3.6).
 *
 * `email` is set when the read is an email follow-up's proposal (RFQ mail M6.2): who sent it, when it arrived and
 * where the message is in the inbox, so the editor can say where the lines came from. Null for a file read.
 */
final class ReaderResult
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $documentPublicId,
        public readonly ReaderJobStatus $status,
        public readonly int $attempts,
        public readonly ?int $baseRevision,
        public readonly ?string $reader,
        public readonly int $proposedLineCount,
        public readonly ?string $note,
        public readonly DateTimeImmutable $updatedAtUtc,
        /** @var array{senderName: string, senderAddress: string, receivedAt: string, subject: string, threadId: string, messageId: string}|null */
        public readonly ?array $email = null,
        public readonly string $fileName = '',
    ) {
    }

    public static function fromStored(StoredReaderJob $job): self
    {
        $rows = is_array($job->proposal['rows'] ?? null) ? $job->proposal['rows'] : [];
        $reader = is_string($job->proposal['reader'] ?? null) ? $job->proposal['reader'] : null;

        $mail = is_array($job->proposal['email'] ?? null) ? $job->proposal['email'] : null;
        if ($mail === null && $job->emailMessageId !== null) {
            // A dismissed or accepted follow-up no longer holds its proposal: the row still says what it was.
            $mail = [];
        }
        $text = static fn (mixed $v): string => is_string($v) ? $v : '';

        return new self(
            StoreProjection::publicId($job->id),
            $job->documentId === null ? '' : StoreProjection::publicId($job->documentId),
            $job->readerStatus(),
            $job->attempts,
            $job->baseRevision,
            $reader,
            count($rows),
            $job->note,
            StoreFormat::parseTime($job->updatedAt) ?? new DateTimeImmutable('@0'),
            $mail === null ? null : [
                'senderName' => $text($mail['sender_name'] ?? null),
                'senderAddress' => $text($mail['sender_address'] ?? null),
                'receivedAt' => $text($mail['received_at'] ?? null),
                'subject' => $text($mail['subject'] ?? null),
                'threadId' => $text($mail['mail_thread_id'] ?? null),
                'messageId' => $text($mail['mail_message_id'] ?? $job->emailMessageId),
            ],
            $job->fileName,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'documentPublicId' => $this->documentPublicId,
            'status' => $this->status->toString(),
            'attempts' => $this->attempts,
            'baseRevision' => $this->baseRevision,
            'reader' => $this->reader,
            'proposedLineCount' => $this->proposedLineCount,
            'note' => $this->note,
            'updatedAt' => $this->updatedAtUtc->format(DateTimeInterface::ATOM),
            'email' => $this->email,
        ];
    }
}
