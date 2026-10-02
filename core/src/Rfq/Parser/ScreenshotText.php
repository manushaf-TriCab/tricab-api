<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The parser's answer to `POST /v1/ocr`, read strictly: the text vision OCR
 * transcribed from one screenshot (parity plan E4.3). Contract `rfq-parser.v1`.
 *
 * It is text for the rep to read and edit before Match Text, never lines: a
 * model's transcription is a claim, and nothing in it is matched, stored or
 * trusted until the person sends it through the ordinary parse.
 */
final class ScreenshotText
{
    private function __construct(
        public readonly string $text,
        public readonly int $rowCount,
        public readonly float $confidence,
        public readonly string $model,
        public readonly bool $escalated,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent): self
    {
        ParseResponse::assertEnvelope($payload, $sent);
        $text = $payload['text'] ?? null;
        $rows = $payload['rowCount'] ?? null;
        $confidence = $payload['confidence'] ?? null;
        $model = $payload['model'] ?? null;
        if (
            !is_string($text) || trim($text) === ''
            || !is_int($rows) || $rows < 0
            || !(is_int($confidence) || is_float($confidence)) || $confidence < 0 || $confidence > 1
            || !is_string($model) || $model === ''
            || !is_bool($payload['escalated'] ?? null)
        ) {
            throw RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned an unreadable screenshot reading.', $sent);
        }

        return new self($text, $rows, (float) $confidence, $model, $payload['escalated']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'rowCount' => $this->rowCount,
            'confidence' => $this->confidence,
            'model' => $this->model,
            'escalated' => $this->escalated,
        ];
    }
}
