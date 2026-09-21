<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * Who is asking for a status change.
 *
 * Three actors, not two, and `system` is named explicitly rather than given a
 * free pass: it opens an item when an attempt starts and completes one when an
 * assessment is passed, and it has no business doing anything else. A "system"
 * that may set any status is indistinguishable from no rule at all.
 *
 * `trainee` keeps the source's word, and the work order's. It means the person
 * the assignment belongs to, which includes employees who are acknowledging a
 * policy rather than being trained in anything.
 *
 * This is **not** a permission check. An actor is established from the
 * authenticated session before the domain sees it: `ONBOARDING.SELF.SUBMIT`
 * over one's own record makes a trainee, `ONBOARDING.REVIEW` makes a reviewer.
 * The domain then decides what that actor may do, and refuses to be told both.
 */
final class StatusActor
{
    public const TRAINEE = 'trainee';
    public const REVIEWER = 'reviewer';
    public const SYSTEM = 'system';

    public const ALL = [self::TRAINEE, self::REVIEWER, self::SYSTEM];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALL, true)) {
            throw InvalidOnboardingValue::forOneOf('StatusActor', $value, self::ALL);
        }

        return new self($normalized);
    }

    public static function trainee(): self
    {
        return new self(self::TRAINEE);
    }

    public static function reviewer(): self
    {
        return new self(self::REVIEWER);
    }

    public static function system(): self
    {
        return new self(self::SYSTEM);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isTrainee(): bool
    {
        return $this->value === self::TRAINEE;
    }

    public function isReviewer(): bool
    {
        return $this->value === self::REVIEWER;
    }

    public function isSystem(): bool
    {
        return $this->value === self::SYSTEM;
    }
}
