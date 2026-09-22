<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * One ticked box: an item, and optionally the date it is due.
 *
 * The due date is a calendar date with no time or zone. It is the day a person
 * is told, and a time zone attached to it would move it by a day for somebody.
 */
final readonly class AllocationSelection
{
    private function __construct(
        public string $itemType,
        public string $itemPublicId,
        public ?string $dueDate,
    ) {
    }

    public static function of(string $itemType, string $itemPublicId, ?string $dueDate): self
    {
        $type = strtolower(trim($itemType));
        if (!in_array($type, ['training', 'policy', 'form'], true)) {
            throw InvalidOnboardingValue::forOneOf('itemType', $itemType, ['training', 'policy', 'form']);
        }

        $id = strtolower(trim($itemPublicId));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            throw InvalidOnboardingValue::for('itemPublicId', $itemPublicId);
        }

        $date = $dueDate === null || trim($dueDate) === '' ? null : trim($dueDate);
        if ($date !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw InvalidOnboardingValue::for('dueDate', $date);
            }
        }

        return new self($type, $id, $date);
    }

    public function key(): string
    {
        return $this->itemType . ':' . $this->itemPublicId;
    }
}
