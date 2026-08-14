<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantProviderResult
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $instructionVersion,
    ) {
    }
}
