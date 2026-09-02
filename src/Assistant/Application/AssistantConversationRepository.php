<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

interface AssistantConversationRepository
{
    public function getOrCreate(
        ?string $publicId,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): AssistantConversation;

    public function find(string $publicId): ?AssistantConversation;

    public function appendTurn(AssistantConversation $conversation, AssistantConversationTurn $turn): void;

    public function persistsAcrossRequests(): bool;
}
