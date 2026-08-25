<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class StyleInventorySourceStat
{
    public function __construct(
        public string $source,
        public string $tableEntity,
        public string $field,
        public int $rowCount,
        public int $nonEmptyStyleCount,
        public int $distinctRawStyleCount,
        public bool $readOnly,
        public bool $available,
        public string $notes = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'tableEntity' => $this->tableEntity,
            'field' => $this->field,
            'rowCount' => $this->rowCount,
            'nonEmptyStyleCount' => $this->nonEmptyStyleCount,
            'distinctRawStyleCount' => $this->distinctRawStyleCount,
            'readOnly' => $this->readOnly,
            'available' => $this->available,
            'notes' => $this->notes,
        ];
    }
}
