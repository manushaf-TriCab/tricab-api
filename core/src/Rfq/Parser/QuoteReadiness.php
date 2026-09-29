<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The source's `quote_readiness` over a draft's rows and header, as the parser
 * answers `POST /v1/readiness`. Contract `rfq-parser.v1`.
 *
 * The rule stays in Python until plan 5.3 ports it: the source computes a
 * saved draft's status with it (`update_draft` → `draft_is_ready`), and a PHP
 * copy written now would be a second definition of "ready" that drifts.
 *
 * `ready` is all a save needs. `blockingIssues` and `rowStates` are kept as
 * the parser sent them for the review screen; they are the source's own
 * shapes and are not reinterpreted here.
 */
final class QuoteReadiness
{
    /**
     * @param list<array<string, mixed>> $blockingIssues
     * @param list<array<string, mixed>> $rowStates
     */
    private function __construct(
        public readonly bool $ready,
        public readonly array $blockingIssues,
        public readonly array $rowStates,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload the decoded JSON body
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent): self
    {
        ParseResponse::assertEnvelope($payload, $sent);

        $readiness = $payload['readiness'] ?? null;
        if (!is_array($readiness) || !is_bool($readiness['ready'] ?? null)) {
            throw self::malformed($sent);
        }

        return new self(
            $readiness['ready'],
            self::listOfObjects($readiness['blocking_issues'] ?? null, $sent),
            self::listOfObjects($readiness['row_states'] ?? null, $sent),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOfObjects(mixed $value, CorrelationId $sent): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::malformed($sent);
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw self::malformed($sent);
            }
            /** @var array<string, mixed> $item */
            $out[] = $item;
        }

        return $out;
    }

    private static function malformed(CorrelationId $sent): RfqParserFailure
    {
        return RfqParserFailure::of(
            RfqParserFailure::INTERNAL_ERROR,
            'The RFQ parser returned an unreadable readiness answer.',
            $sent,
        );
    }
}
