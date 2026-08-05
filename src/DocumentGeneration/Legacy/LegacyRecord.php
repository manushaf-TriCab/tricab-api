<?php

declare(strict_types=1);

namespace TriCab\Core\DocumentGeneration\Legacy;

final readonly class LegacyRecord
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        public string $type,
        public int $declaredFieldCount,
        public array $fields,
        public int $sourceLine,
    ) {
    }

    public function field(int $zeroBasedIndex, string $default = ''): string
    {
        return $this->fields[$zeroBasedIndex] ?? $default;
    }
}
