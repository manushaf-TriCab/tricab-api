<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * A reader job's result, as the parser returns it. Contract `rfq-parser.v1`,
 * `POST /v1/read`.
 *
 * Parsed as strictly as {@see ParseResponse}. A read that produced no lines is
 * never this: the parser answers a typed {@see RfqParserFailure} instead, so
 * `provider_rejected` (credentials, a refused request) parks the job and
 * `provider_unavailable` (quota, 5xx, unreachable) retries it.
 *
 * `answeredBy` is which reader the rows came from, read off the rows
 * (`reader`, `vision_ocr`, or `deterministic`), which is what the draft's
 * activity names — the source's `_reader_name`.
 */
final class ReadResponse
{
    /**
     * @param list<array<string, mixed>> $lines
     * @param list<string>               $notes
     * @param array<string, string>      $models
     */
    private function __construct(
        public readonly string $correlationId,
        public readonly array $lines,
        public readonly array $notes,
        public readonly bool $degraded,
        public readonly string $answeredBy,
        public readonly ?string $model,
        public readonly array $models,
        public readonly int $modelCalls,
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

        $lines = $payload['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || $lines === []) {
            throw self::malformed($sent, 'lines');
        }
        $typedLines = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw self::malformed($sent, 'lines');
            }
            /** @var array<string, mixed> $line */
            $typedLines[] = $line;
        }
        $notes = $payload['notes'] ?? [];
        if (!is_array($notes) || !array_is_list($notes)) {
            throw self::malformed($sent, 'notes');
        }
        $typedNotes = [];
        foreach ($notes as $note) {
            if (!is_string($note)) {
                throw self::malformed($sent, 'notes');
            }
            $typedNotes[] = $note;
        }
        $degraded = $payload['degraded'] ?? null;
        $provider = $payload['provider'] ?? null;
        if (!is_bool($degraded)) {
            throw self::malformed($sent, 'degraded');
        }
        if (!is_array($provider)
            || !in_array($provider['answeredBy'] ?? null, ['reader', 'vision_ocr', 'deterministic'], true)
            || !is_int($provider['modelCalls'] ?? null)
            || !(($provider['model'] ?? null) === null || is_string($provider['model']))
            || !is_array($provider['models'] ?? [])) {
            throw self::malformed($sent, 'provider');
        }
        $models = [];
        foreach ($provider['models'] ?? [] as $path => $name) {
            if (is_string($path) && is_string($name)) {
                $models[$path] = $name;
            }
        }

        $model = $provider['model'] ?? null;

        return new self($sent->toString(), $typedLines, $typedNotes, $degraded, $provider['answeredBy'], is_string($model) ? $model : null, $models, $provider['modelCalls']);
    }

    /** The reader's name for the draft's activity: the model, or what answered. */
    public function readerName(): string
    {
        return match ($this->answeredBy) {
            'reader' => $this->models['reader'] ?? $this->model ?? 'the attachment reader',
            'vision_ocr' => $this->models['vision_ocr'] ?? $this->model ?? 'vision OCR',
            default => 'the deterministic parser',
        };
    }

    private static function malformed(CorrelationId $sent, string $field): RfqParserFailure
    {
        return RfqParserFailure::of(
            RfqParserFailure::INTERNAL_ERROR,
            sprintf('The RFQ reader returned an unreadable result (%s).', $field),
            $sent,
        );
    }
}
