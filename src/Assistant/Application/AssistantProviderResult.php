<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantProviderResult
{
    /**
     * @param list<array<string, mixed>> $cards
     * @param list<string> $toolNames
     */
    public function __construct(
        public string $text,
        public string $provider,
        public string $instructionVersion,
        public array $cards = [],
        public array $toolNames = [],
    ) {
    }
}
