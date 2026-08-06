<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class SpecificationImportResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $skipped,
        public array $errors = [],
    ) {
    }

    /** @return array{created: int, updated: int, skipped: int, errors: list<string>} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
