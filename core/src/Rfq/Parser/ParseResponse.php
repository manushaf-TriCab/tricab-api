<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * A parse result, as the parser returns it. Contract `rfq-parser.v1`, response.
 *
 * Parsed strictly: the wrong version, a missing field or a coverage count that
 * is not a non-negative integer is a {@see RfqParserFailure}, never a partial
 * result. Lines stay as arrays here; Phase 3 maps each into a `ParsedLine`
 * after the deterministic validation the plan requires (invariant 2), and
 * that mapping — not this class — decides what a line may claim.
 *
 * `coverage` is the source accounting (plan 3.4, invariant 3): every input row
 * is kept, excluded or skipped, and {@see isCoverageComplete()} is false when
 * the three do not add up. A parse whose coverage does not balance cannot make
 * an RFQ ready, however well the returned rows match.
 *
 * `engine` and `provider` are recorded with the result (plan 3.6), so a
 * changed code on a re-parse can be traced to a rules, catalogue or model
 * change rather than guessed at.
 */
final class ParseResponse
{
    /**
     * @param list<array<string, mixed>> $lines
     * @param array{inputRows: int, keptRows: int, excludedRows: int, skippedRows: int} $coverage
     * @param array{version: string, rulesVersion: string, catalogueVersion: string} $engine
     * @param array{mode: string, modelCalls: int, model: ?string} $provider
     * @param list<string> $notes
     */
    private function __construct(
        public readonly string $correlationId,
        public readonly array $lines,
        public readonly array $coverage,
        public readonly array $engine,
        public readonly array $provider,
        public readonly bool $degraded,
        public readonly array $notes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload the decoded JSON body
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent): self
    {
        if (($payload['contract'] ?? null) !== ParserContract::VERSION) {
            throw RfqParserFailure::of(
                RfqParserFailure::CONTRACT_MISMATCH,
                'The RFQ parser answered with a different contract version.',
                $sent,
            );
        }

        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            $code = is_string($error['code'] ?? null) ? $error['code'] : RfqParserFailure::INTERNAL_ERROR;
            $message = is_string($error['message'] ?? null) && $error['message'] !== ''
                ? $error['message']
                : 'The RFQ parser could not read this request.';

            throw RfqParserFailure::of($code, $message, $sent);
        }

        $lines = $payload['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines)) {
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

        $coverage = $payload['coverage'] ?? null;
        if (!is_array($coverage)) {
            throw self::malformed($sent, 'coverage');
        }
        $coverage = [
            'inputRows' => self::count($coverage, 'inputRows', $sent),
            'keptRows' => self::count($coverage, 'keptRows', $sent),
            'excludedRows' => self::count($coverage, 'excludedRows', $sent),
            'skippedRows' => self::count($coverage, 'skippedRows', $sent),
        ];

        $engine = $payload['engine'] ?? null;
        if (!is_array($engine)) {
            throw self::malformed($sent, 'engine');
        }
        $engine = [
            'version' => self::label($engine, 'version', $sent),
            'rulesVersion' => self::label($engine, 'rulesVersion', $sent),
            'catalogueVersion' => self::label($engine, 'catalogueVersion', $sent),
        ];

        $provider = $payload['provider'] ?? null;
        if (!is_array($provider)) {
            throw self::malformed($sent, 'provider');
        }
        $mode = $provider['mode'] ?? null;
        $modelCalls = $provider['modelCalls'] ?? null;
        $model = $provider['model'] ?? null;
        if (($mode !== 'deterministic' && $mode !== 'model')
            || !is_int($modelCalls) || $modelCalls < 0
            || !($model === null || is_string($model))) {
            throw self::malformed($sent, 'provider');
        }

        $degraded = $payload['degraded'] ?? null;
        if (!is_bool($degraded)) {
            throw self::malformed($sent, 'degraded');
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

        $returnedId = $payload['correlationId'] ?? null;
        if ($returnedId !== $sent->toString()) {
            throw self::malformed($sent, 'correlationId');
        }

        return new self(
            $sent->toString(),
            $typedLines,
            $coverage,
            $engine,
            ['mode' => $mode, 'modelCalls' => $modelCalls, 'model' => $model],
            $degraded,
            $typedNotes,
        );
    }

    public function isCoverageComplete(): bool
    {
        return $this->coverage['inputRows']
            === $this->coverage['keptRows'] + $this->coverage['excludedRows'] + $this->coverage['skippedRows'];
    }

    /** @param array<array-key, mixed> $map */
    private static function count(array $map, string $key, CorrelationId $sent): int
    {
        $n = $map[$key] ?? null;
        if (!is_int($n) || $n < 0) {
            throw self::malformed($sent, 'coverage.' . $key);
        }

        return $n;
    }

    /** @param array<array-key, mixed> $map */
    private static function label(array $map, string $key, CorrelationId $sent): string
    {
        $s = $map[$key] ?? null;
        if (!is_string($s) || $s === '') {
            throw self::malformed($sent, 'engine.' . $key);
        }

        return $s;
    }

    private static function malformed(CorrelationId $sent, string $field): RfqParserFailure
    {
        return RfqParserFailure::of(
            RfqParserFailure::INTERNAL_ERROR,
            sprintf('The RFQ parser returned an unreadable result (%s).', $field),
            $sent,
        );
    }
}
