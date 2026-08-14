<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantConversationTurn
{
    public function __construct(
        public string $userMessage,
        public string $assistantMessage,
    ) {
    }
}
