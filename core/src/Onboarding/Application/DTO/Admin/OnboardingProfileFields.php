<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * The editable part of an induction record: where the person will work and
 * when they start.
 *
 * Their name and email are not here. Those belong to their platform account,
 * and an Onboarding screen that could rename somebody's account would be a
 * second, unaudited User Management.
 */
final readonly class OnboardingProfileFields
{
    private const MAX_LENGTH = 200;

    private function __construct(
        public ?string $position,
        public ?string $department,
        public ?string $branch,
        public ?string $startDate,
    ) {
    }

    public static function of(?string $position, ?string $department, ?string $branch, ?string $startDate): self
    {
        $start = self::clean('startDate', $startDate);
        if ($start !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
            if ($parsed === false || $parsed->format('Y-m-d') !== $start) {
                throw InvalidOnboardingValue::for('startDate', $start);
            }
        }

        return new self(
            self::clean('position', $position),
            self::clean('department', $department),
            self::clean('branch', $branch),
            $start,
        );
    }

    /**
     * The names of the fields that differ — for the audit row, which records
     * that a field changed and never what it now says.
     *
     * @return list<string>
     */
    public function changedFrom(OnboardingPersonSummary $current): array
    {
        $changed = [];
        foreach (['position', 'department', 'branch', 'startDate'] as $field) {
            if ($this->{$field} !== $current->{$field}) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private static function clean(string $field, ?string $value): ?string
    {
        $trimmed = $value === null ? '' : trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidOnboardingValue::for($field, sprintf('(longer than %d characters)', self::MAX_LENGTH));
        }

        return $trimmed;
    }
}
