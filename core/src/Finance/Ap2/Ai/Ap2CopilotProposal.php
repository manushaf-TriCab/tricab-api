<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/**
 * Something Copilot suggests. It is never applied by the server: the web applies it through the normal route.
 *
 * Types and payloads (the contract's "Additions (web round 3)"):
 *  - `record_action`: `{supplierId, type, detail}`
 *  - `apply_draft`: `{mailId, draft}`
 *  - `start_verification`: `{invoiceId, fields: string[]}`
 *  - `request_approval`: `{kind, invoiceId|mailId|paymentId, subject?, summary?}`
 */
final readonly class Ap2CopilotProposal
{
    public const TYPES = ['record_action', 'apply_draft', 'start_verification', 'request_approval'];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $type,
        public string $label,
        public array $payload = [],
    ) {
    }
}
