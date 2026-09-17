<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\Policy;

use DateInterval;
use DateTimeImmutable;

/**
 * Provisional policy matching legacy createNewQuote (+2 calendar days).
 * Marked BUSINESS_CONFIRMATION_REQUIRED — replace after business sign-off.
 */
final class ProvisionalPlusTwoDayQuoteValidityPolicy implements QuoteValidityPolicy
{
    public const CONFIRMATION_STATUS = 'BUSINESS_CONFIRMATION_REQUIRED';

    public function validUntil(DateTimeImmutable $createdAt): DateTimeImmutable
    {
        return $createdAt->add(new DateInterval('P2D'));
    }

    public function confirmationStatus(): string
    {
        return self::CONFIRMATION_STATUS;
    }

    public function description(): string
    {
        return 'Provisional +2 days from createdAt (legacy createNewQuote). Older add_quote_header used +7 days.';
    }
}
