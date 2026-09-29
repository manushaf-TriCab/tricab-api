<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/**
 * One reader job's read, as PHP sends it to the parser. Contract
 * `rfq-parser.v1`, `POST /v1/read` (plan 3.5).
 *
 * The source's portal re-read (`reader_jobs.run_reader_jobs`) with the job
 * held here: the worker claims the job, sends the document's bytes, and
 * applies or proposes what comes back. `context` is the source worker's
 * message context — the draft's label and the files uploaded with it — which
 * is what lets the terra translator run. A read is a model call, so
 * `allowModel` is always true; `maxModelCalls` caps the translator.
 */
final class ReadRequest
{
    /**
     * @param list<string> $attachments display names of the files uploaded together
     */
    private function __construct(
        public readonly CorrelationId $correlationId,
        public readonly int $deadlineMs,
        public readonly string $documentPublicId,
        public readonly string $fileName,
        public readonly string $contentType,
        public readonly string $bytes,
        public readonly string $subject,
        public readonly array $attachments,
        public readonly int $maxModelCalls,
    ) {
    }

    /**
     * @param list<string> $attachments
     */
    public static function forDocument(
        CorrelationId $correlationId,
        string $documentPublicId,
        string $fileName,
        string $contentType,
        string $bytes,
        string $subject,
        array $attachments,
        int $deadlineMs = RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS,
    ): self {
        $size = strlen($bytes);
        $cap = str_starts_with(strtolower($contentType), 'image/') ? RfqServiceLimits::MAX_IMAGE_BYTES : RfqServiceLimits::MAX_DOCUMENT_BYTES;
        if ($size === 0 || $size > $cap) {
            throw InvalidRfqValue::outOfRange('document size in bytes', $size, 1, $cap);
        }
        if ($deadlineMs < 1_000 || $deadlineMs > RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS) {
            throw InvalidRfqValue::outOfRange('parser deadline in ms', $deadlineMs, 1_000, RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS);
        }

        return new self($correlationId, $deadlineMs, $documentPublicId, $fileName, $contentType, $bytes, $subject, $attachments, RfqServiceLimits::MAX_MODEL_CALLS_PER_PARSE);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'contract' => ParserContract::VERSION,
            'correlationId' => $this->correlationId->toString(),
            'deadlineMs' => $this->deadlineMs,
            'document' => [
                'documentPublicId' => $this->documentPublicId,
                'fileName' => $this->fileName,
                'contentType' => $this->contentType,
                'contentBase64' => base64_encode($this->bytes),
            ],
            'options' => ['allowModel' => true, 'maxModelCalls' => $this->maxModelCalls],
            'context' => ['subject' => $this->subject, 'attachments' => $this->attachments],
        ];
    }
}
