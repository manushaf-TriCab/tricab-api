<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The parser's answer to `POST /v1/sync-plan` (parity plan E7.3), read strictly:
 * the 4js quote plan for a draft, or why none can be made. A problem is an
 * answer, not a failure of the parser.
 *
 * `plan` is the source's `prepare_quote_push` plan: `hdr_params`, `line_items`,
 * `excluded`, `fallback_margin`, `is_update`, `existing_refno`, `push_version`,
 * `customer_id`, `cusref`. `problem` is `{status, payload}`; its payload is the
 * source's (`error`, `blocking_issues`, `price_changes`, `unpriced`, `excluded`).
 */
final class QuotePlan
{
    /**
     * @param array<string, mixed>|null                               $plan
     * @param array{status: int, payload: array<string, mixed>}|null  $problem
     */
    private function __construct(public readonly ?array $plan, public readonly ?array $problem)
    {
    }

    /** @param array<string, mixed> $plan */
    public static function ready(array $plan): self
    {
        return new self($plan, null);
    }

    /** @param array<string, mixed> $payload */
    public static function blocked(int $status, array $payload): self
    {
        return new self(null, ['status' => $status, 'payload' => $payload]);
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent): self
    {
        ParseResponse::assertEnvelope($payload, $sent);
        $plan = $payload['plan'] ?? null;
        $problem = $payload['problem'] ?? null;
        if (is_array($plan) && $problem === null && !array_is_list($plan)
            && is_array($plan['hdr_params'] ?? null) && is_array($plan['line_items'] ?? null) && array_is_list($plan['line_items'])
            && is_array($plan['excluded'] ?? null) && is_array($plan['fallback_margin'] ?? null)
            && is_bool($plan['is_update'] ?? null) && is_string($plan['push_version'] ?? null)) {
            /** @var array<string, mixed> $plan */
            return new self($plan, null);
        }
        if ($plan === null && is_array($problem) && is_int($problem['status'] ?? null) && is_array($problem['payload'] ?? null)) {
            /** @var array<string, mixed> $body */
            $body = $problem['payload'];

            return new self(null, ['status' => $problem['status'], 'payload' => $body]);
        }

        throw RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned an unreadable sync plan.', $sent);
    }
}
