<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** The model's prose for one email (`ar_prose`): who the customer is, what they want, and a draft reply. */
final readonly class ArProse
{
    public function __construct(
        public string $customerName,
        public string $aiSummary,
        public string $proposedResponse,
    ) {
    }
}
