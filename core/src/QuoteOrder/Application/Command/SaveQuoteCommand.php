<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Command;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Internal Save Quote command — not an HTTP DTO.
 * Clients may only supply public ids + reference + Idempotency-Key.
 */
final readonly class SaveQuoteCommand
{
    public function __construct(
        public AuthenticatedUser $actor,
        public string $customerPublicId,
        public string $cartPublicId,
        public string $reference,
        public ?string $contactPublicId = null,
        public ?string $pricingContextToken = null,
        public ?string $idempotencyKey = null,
        public bool $dryRun = true,
        public ?int $raisedByOperatorIdOverride = null,
        public ?int $recipientContactLegacyIdOverride = null,
    ) {
    }
}
