<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use DateTimeImmutable;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * The part of a person's induction record an HR write needs: who they are, the
 * token two HR users race on, and whether they have left.
 */
final readonly class OnboardingPersonRecord
{
    public function __construct(
        public int $userId,
        public string $publicId,
        public RowVersionToken $rowVersion,
        public ?DateTimeImmutable $departedAtUtc,
    ) {
    }
}
