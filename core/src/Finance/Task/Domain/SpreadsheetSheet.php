<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/**
 * One sheet as raw cells. A cell is what the file held: text, a number, a boolean, a
 * `DateTimeInterface` (an Excel date), or null. `title` is the sheet's name; a CSV has none.
 */
final readonly class SpreadsheetSheet
{
    /**
     * @param list<list<mixed>> $rows every row, empty ones included, so a row's position is its row number
     */
    public function __construct(
        public ?string $title,
        public array $rows,
    ) {
    }
}
