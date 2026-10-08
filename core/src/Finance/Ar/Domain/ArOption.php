<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

use DateTimeImmutable;

/**
 * One entry of the Category or Method list (`option_lists`). `description` is the criterion the
 * classifier is given. A built-in option (`isSystem`) cannot be archived or removed.
 */
final readonly class ArOption
{
    public const LIST_CATEGORY = 'category';
    public const LIST_METHOD = 'method';
    public const LISTS = [self::LIST_CATEGORY, self::LIST_METHOD];

    public function __construct(
        public string $listName,
        public string $value,
        public string $label,
        public string $description,
        public string $color,
        public int $sortOrder,
        public bool $active,
        public bool $isSystem,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /** A copy with some properties replaced (by property name). */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
