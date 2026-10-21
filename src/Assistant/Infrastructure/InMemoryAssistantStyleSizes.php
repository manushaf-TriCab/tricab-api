<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantStyleSizes;

final class InMemoryAssistantStyleSizes implements AssistantStyleSizes
{
    /** @var array<string, array<int, list<float>>> */
    private array $sizes = [];

    public function add(string $styleCode, int $coreCount, float ...$sizesMm2): void
    {
        $style = strtoupper($styleCode);
        $known = $this->sizes[$style][$coreCount] ?? [];
        $merged = array_values(array_unique([...$known, ...$sizesMm2], SORT_REGULAR));
        sort($merged);
        $this->sizes[$style][$coreCount] = $merged;
    }

    public function forStyle(string $styleCode): array
    {
        $byCores = $this->sizes[strtoupper(trim($styleCode))] ?? [];
        ksort($byCores);

        $options = [];
        foreach ($byCores as $cores => $sizes) {
            $options[] = ['coreCount' => $cores, 'sizesMm2' => $sizes];
        }

        return $options;
    }
}
