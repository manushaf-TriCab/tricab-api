<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantToolResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $cards
     */
    public function __construct(
        public array $data,
        public array $cards = [],
    ) {
    }
}
