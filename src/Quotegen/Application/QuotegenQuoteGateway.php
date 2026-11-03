<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

/**
 * What the assistant's tools need from the Quotegen core services. Deliberately
 * has no sync method: the assistant can only propose one.
 */
interface QuotegenQuoteGateway
{
    /**
     * The RFQ as the actor may see it (pricing present only with `RFQ.PRICING.VIEW`).
     *
     * @return array{revision: int|null, rfq: array<string, mixed>}|null null when there is no such RFQ for this actor
     */
    public function snapshot(QuotegenTurnContext $context): ?array;

    /**
     * @return array<string, mixed>|null null when there is no such RFQ for this actor
     */
    public function readiness(QuotegenTurnContext $context): ?array;

    /** @return list<array<string, mixed>> */
    public function searchProducts(string $query): array;

    /** @return list<array<string, mixed>> */
    public function findCustomers(string $query): array;

    /**
     * Saves line and header edits through the normal revision-checked save, from
     * `$context->revision`.
     *
     * @param array<string, mixed> $edits the validated `update_lines` input
     *
     * @return array{revision: int, changedLineIds: list<string>}
     *
     * @throws \TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict
     * @throws \TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue
     * @throws \TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden
     * @throws \TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable
     */
    public function applyEdits(QuotegenTurnContext $context, array $edits): array;
}
