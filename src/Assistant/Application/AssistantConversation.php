<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final class AssistantConversation
{
    /** @var list<AssistantConversationTurn> */
    private array $turns = [];

    public function __construct(
        public readonly string $publicId,
    ) {
    }

    public function append(AssistantConversationTurn $turn): void
    {
        $this->turns[] = $turn;
    }

    /** @return list<AssistantConversationTurn> */
    public function turns(): array
    {
        return $this->turns;
    }
}
