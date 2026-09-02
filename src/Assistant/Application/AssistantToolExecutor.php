<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

interface AssistantToolExecutor
{
    /**
     * Anthropic tool definitions. Every schema must set additionalProperties=false
     * so tool input cannot silently broaden a server-authorized query.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array;

    /**
     * @param array<string, mixed> $input
     */
    public function execute(
        string $name,
        array $input,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): AssistantToolResult;
}
