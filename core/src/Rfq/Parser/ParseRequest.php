<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/**
 * A parse, as PHP sends it to the parser. Contract `rfq-parser.v1`, request.
 *
 * Bytes travel inline, base64, rather than as a blob key: the parser then
 * needs no storage credential, and a document it was never sent is a
 * document it cannot read. Limits are enforced here, before anything is
 * encoded, so an oversized upload is refused by PHP with a 413 and never
 * reaches Python.
 *
 * `allowModel` is decided by PHP from configuration, per request. Extracted
 * text cannot turn it on (plan 3.5: content never authorizes tools, sends or
 * submission).
 */
final class ParseRequest
{
    /**
     * @param list<array{documentPublicId: string, fileName: string, contentType: string, bytes: string}> $documents
     */
    private function __construct(
        public readonly CorrelationId $correlationId,
        public readonly int $deadlineMs,
        public readonly ?string $text,
        public readonly array $documents,
        public readonly bool $allowModel,
        public readonly int $maxModelCalls,
    ) {
    }

    public static function forText(
        CorrelationId $correlationId,
        string $text,
        bool $allowModel,
        int $deadlineMs = RfqServiceLimits::INTERACTIVE_PARSE_DEADLINE_MS,
    ): self {
        if (trim($text) === '') {
            throw InvalidRfqValue::required('RFQ text', 'paste the lines to parse.');
        }
        $length = mb_strlen($text);
        if ($length > RfqServiceLimits::MAX_PASTED_CHARACTERS) {
            throw InvalidRfqValue::outOfRange('pasted text length', $length, 1, RfqServiceLimits::MAX_PASTED_CHARACTERS);
        }

        return new self($correlationId, self::deadline($deadlineMs), $text, [], $allowModel, RfqServiceLimits::MAX_MODEL_CALLS_PER_PARSE);
    }

    /**
     * @param list<array{documentPublicId: string, fileName: string, contentType: string, bytes: string}> $documents
     */
    public static function forDocuments(
        CorrelationId $correlationId,
        array $documents,
        bool $allowModel,
        int $deadlineMs = RfqServiceLimits::INTERACTIVE_PARSE_DEADLINE_MS,
    ): self {
        $count = count($documents);
        if ($count === 0) {
            throw InvalidRfqValue::required('document', 'attach at least one file.');
        }
        if ($count > RfqServiceLimits::MAX_DOCUMENTS_PER_INTAKE) {
            throw InvalidRfqValue::outOfRange('document count', $count, 1, RfqServiceLimits::MAX_DOCUMENTS_PER_INTAKE);
        }
        foreach ($documents as $document) {
            $size = strlen($document['bytes']);
            $cap = str_starts_with(strtolower($document['contentType']), 'image/')
                ? RfqServiceLimits::MAX_IMAGE_BYTES
                : RfqServiceLimits::MAX_DOCUMENT_BYTES;
            if ($size === 0 || $size > $cap) {
                throw InvalidRfqValue::outOfRange('document size in bytes', $size, 1, $cap);
            }
        }

        return new self($correlationId, self::deadline($deadlineMs), null, $documents, $allowModel, RfqServiceLimits::MAX_MODEL_CALLS_PER_PARSE);
    }

    /**
     * The JSON body. File names are display metadata, sent so the parser can
     * pick a reader by extension and label its source rows — never used as a
     * path on either side.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'contract' => ParserContract::VERSION,
            'correlationId' => $this->correlationId->toString(),
            'deadlineMs' => $this->deadlineMs,
            'text' => $this->text,
            'documents' => array_map(
                static fn (array $d): array => [
                    'documentPublicId' => $d['documentPublicId'],
                    'fileName' => $d['fileName'],
                    'contentType' => $d['contentType'],
                    'contentBase64' => base64_encode($d['bytes']),
                ],
                $this->documents,
            ),
            'options' => [
                'allowModel' => $this->allowModel,
                'maxModelCalls' => $this->maxModelCalls,
            ],
        ];
    }

    private static function deadline(int $deadlineMs): int
    {
        if ($deadlineMs < 1_000 || $deadlineMs > RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS) {
            throw InvalidRfqValue::outOfRange('parser deadline in ms', $deadlineMs, 1_000, RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS);
        }

        return $deadlineMs;
    }
}
