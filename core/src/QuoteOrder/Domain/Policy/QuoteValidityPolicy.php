<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\Policy;

use DateInterval;
use DateTimeImmutable;

/**
 * Quote validity is a business policy — not a repository constant.
 *
 * BUSINESS CONFIRMATION REQUIRED: modern legacy create uses +2 days; older
 * add_quote_header uses +7 days. Default below mirrors modern create only as
 * a provisional policy until business confirms.
 */
interface QuoteValidityPolicy
{
    public function validUntil(DateTimeImmutable $createdAt): DateTimeImmutable;

    /** Human-readable status for reports. */
    public function confirmationStatus(): string;

    public function description(): string;
}
