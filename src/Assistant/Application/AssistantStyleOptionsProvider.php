<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

interface AssistantStyleOptionsProvider
{
    /**
     * @param list<string> $styleCodes
     * @return list<array<string, mixed>>
     */
    public function list(array $styleCodes, ?string $cableType, int $limit): array;
}
