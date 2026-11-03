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
    /** `contract.MAX_CORRECTIONS`. */
    public const MAX_CORRECTIONS = 50_000;

    /** The envelope contract {@see self::forEmail()} sends (`rfq_parser.mail_envelope.CONTRACT`). */
    public const EMAIL_ENVELOPE_CONTRACT = 'rfq-mail-envelope.v1';

    /** `mail_envelope.MAX_DOCUMENTS`: what an envelope may carry. The parser reads at most 10 of them. */
    public const MAX_EMAIL_DOCUMENTS = 40;

    /**
     * Everything an email's attachments may add up to, before base64. The parser's HTTP body is
     * capped at 25 MB (`app.MAX_UPLOAD_BYTES`) and base64 adds a third, so this leaves room for
     * the body and the headers. A mail reader drops what does not fit and says so.
     */
    public const MAX_EMAIL_ATTACHMENT_BYTES = 17 * 1024 * 1024;

    /** @var array<string, array{product_code: string, confirmations: int}> */
    private array $corrections = [];

    /**
     * @param list<array{documentPublicId: string, fileName: string, contentType: string, bytes: string}> $documents
     * @param array<string, mixed>|null $email the envelope, attachments in `documents[]` with raw `bytes`
     */
    private function __construct(
        public readonly CorrelationId $correlationId,
        public readonly int $deadlineMs,
        public readonly ?string $text,
        public readonly array $documents,
        public readonly bool $allowModel,
        public readonly int $maxModelCalls,
        public readonly ?array $email = null,
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
     * One email: an `rfq-mail-envelope.v1` (`docs/rfq-mail-envelope.md`), the third input
     * beside text and documents. The body and every attachment are parsed in one pass.
     *
     * The envelope is the raw Graph message under the contract's field names: `body` is
     * `{contentType: html|text, content}` exactly as Graph gives it, and attachments are
     * `documents[]` entries like {@see self::forDocuments()}'s, with raw `bytes` (encoded
     * here), plus optional `isInline` and `contentId`. The `contract` field is added here.
     * Only what is structurally unusable is refused in PHP; the parser is the authority on
     * the rest and names the field it dislikes.
     *
     * Limits are those of a document request, per attachment, and a total (`MAX_EMAIL_ATTACHMENT_BYTES`)
     * that keeps the whole request inside what the parser accepts. A caller whose message does not
     * fit leaves attachments out itself and records that it did: an attachment dropped here without a
     * trace would be a file nobody read.
     *
     * @param array<string, mixed> $envelope
     */
    public static function forEmail(
        CorrelationId $correlationId,
        array $envelope,
        bool $allowModel,
        int $deadlineMs = RfqServiceLimits::INTERACTIVE_PARSE_DEADLINE_MS,
    ): self {
        $id = $envelope['internetMessageId'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            throw InvalidRfqValue::required('internetMessageId', 'an email needs its internet message id.');
        }
        $body = $envelope['body'] ?? null;
        if (!is_array($body) || !in_array($body['contentType'] ?? null, ['html', 'text'], true) || !is_string($body['content'] ?? null)) {
            throw InvalidRfqValue::required('email body', 'send body.contentType (html or text) and body.content.');
        }
        $documents = $envelope['documents'] ?? [];
        if (!is_array($documents) || !array_is_list($documents)) {
            throw InvalidRfqValue::required('documents', 'attachments are a list.');
        }
        $count = count($documents);
        if ($count > self::MAX_EMAIL_DOCUMENTS) {
            throw InvalidRfqValue::outOfRange('attachment count', $count, 0, self::MAX_EMAIL_DOCUMENTS);
        }
        $total = 0;
        foreach ($documents as $document) {
            if (!is_array($document) || !is_string($document['bytes'] ?? null)) {
                throw InvalidRfqValue::required('attachment bytes', 'each attachment needs documentPublicId, fileName, contentType and bytes.');
            }
            $size = strlen($document['bytes']);
            $cap = str_starts_with(strtolower(is_string($document['contentType'] ?? null) ? $document['contentType'] : ''), 'image/')
                ? RfqServiceLimits::MAX_IMAGE_BYTES
                : RfqServiceLimits::MAX_DOCUMENT_BYTES;
            if ($size === 0 || $size > $cap) {
                throw InvalidRfqValue::outOfRange('attachment size in bytes', $size, 1, $cap);
            }
            $total += $size;
        }
        if ($total > self::MAX_EMAIL_ATTACHMENT_BYTES) {
            throw InvalidRfqValue::outOfRange('attachment bytes in one email', $total, 0, self::MAX_EMAIL_ATTACHMENT_BYTES);
        }

        return new self($correlationId, self::deadline($deadlineMs), null, [], $allowModel, RfqServiceLimits::MAX_MODEL_CALLS_PER_PARSE, $envelope);
    }

    /**
     * The same request carrying correction memory: `{key => {product_code,
     * confirmations}}`, keys as `/v1/corrections/keys` makes them, ties
     * already left out ({@see \TriCab\Core\Rfq\Contract\RfqCorrectionMemory}).
     * At most {@see self::MAX_CORRECTIONS}; the parser refuses more.
     *
     * @param array<string, array{product_code: string, confirmations: int}> $corrections
     */
    public function withCorrections(array $corrections): self
    {
        $copy = clone $this;
        $copy->corrections = array_slice($corrections, 0, self::MAX_CORRECTIONS, true);

        return $copy;
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
        $payload = [
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
        if ($this->email !== null) {
            $payload['email'] = $this->emailPayload($this->email);
        }
        if ($this->corrections !== []) {
            // An object even when every key looks like an index.
            $payload['corrections'] = (object) $this->corrections;
        }

        return $payload;
    }

    /**
     * The envelope as the parser reads it: the contract named, attachments in the `documents[]`
     * shape with base64 content, and fields the contract does not have left out so a producer's
     * extra keys never fail an otherwise good message.
     *
     * @param array<string, mixed> $envelope
     *
     * @return array<string, mixed>
     */
    private function emailPayload(array $envelope): array
    {
        $out = ['contract' => self::EMAIL_ENVELOPE_CONTRACT];
        foreach (['internetMessageId', 'messageId', 'conversationId', 'receivedAt', 'mailbox', 'subject', 'sender', 'toRecipients', 'ccRecipients', 'body'] as $key) {
            if (array_key_exists($key, $envelope)) {
                $out[$key] = $envelope[$key];
            }
        }
        $out['documents'] = [];
        $documents = $envelope['documents'] ?? [];
        foreach (is_array($documents) ? $documents : [] as $d) {
            if (!is_array($d) || !is_string($d['bytes'] ?? null)) {
                continue;
            }
            $document = [
                'documentPublicId' => $d['documentPublicId'] ?? null,
                'fileName' => $d['fileName'] ?? null,
                'contentType' => $d['contentType'] ?? null,
                'contentBase64' => base64_encode($d['bytes']),
                'isInline' => (bool) ($d['isInline'] ?? false),
            ];
            if (isset($d['contentId']) && is_string($d['contentId'])) {
                $document['contentId'] = $d['contentId'];
            }
            $out['documents'][] = $document;
        }

        return $out;
    }

    private static function deadline(int $deadlineMs): int
    {
        if ($deadlineMs < 1_000 || $deadlineMs > RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS) {
            throw InvalidRfqValue::outOfRange('parser deadline in ms', $deadlineMs, 1_000, RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS);
        }

        return $deadlineMs;
    }
}
