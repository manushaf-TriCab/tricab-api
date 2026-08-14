<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

interface AssistantConversationRepository
{
    public function getOrCreate(?string $publicId): AssistantConversation;

    public function find(string $publicId): ?AssistantConversation;
}
