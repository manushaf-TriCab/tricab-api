<?php

declare(strict_types=1);

namespace TriCab\Core\DocumentGeneration\Legacy;

use TriCab\Core\DocumentGeneration\Application\Exception\InvalidOcrStream;

final readonly class LegacyStream
{
    /**
     * @param list<LegacyRecord> $records
     * @param list<string> $warnings
     * @param list<array{sizeMm2: string, tricab: string|null, class5: string|null, class6: string|null}> $conductorComparison
     */
    public function __construct(
        public string $selector,
        public array $records,
        public array $warnings = [],
        public array $conductorComparison = [],
    ) {
    }

    public function templateName(): string
    {
        $path = str_replace('\\', '/', substr($this->selector, 5));
        $name = basename($path);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new InvalidOcrStream('The OCR stream has an invalid template selector.');
        }

        return $name;
    }

    public function first(string $type): ?LegacyRecord
    {
        foreach ($this->records as $record) {
            if ($record->type === $type) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return list<LegacyRecord>
     */
    public function all(string $type): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (LegacyRecord $record): bool => $record->type === $type,
        ));
    }
}
