<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;

/**
 * Result of Save Quote preparation / dry-run. Never exposes legacy table names.
 */
final readonly class SaveQuotePreparationResult
{
    /**
     * @param list<string> $blockers
     * @param list<SaveQuoteLinePricingSnapshot> $lineSnapshots
     * @param array<string, mixed>|null $mappedPreview
     */
    public function __construct(
        public bool $readyToPersist,
        public array $blockers,
        public ?PersistQuoteDraft $draft,
        public array $lineSnapshots,
        public bool $hasTbcLines,
        public string $currencyCode,
        public string $statusCode,
        public string $validUntil,
        public string $validityPolicyStatus,
        public ?array $mappedPreview,
        public string $totalNet,
        public string $totalStatus,
        public ?string $platformOperationId = null,
        public ?string $requestHash = null,
        public ?string $reconciliationFingerprint = null,
    ) {
    }
}
