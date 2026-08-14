<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantConversation;
use TriCab\Api\Assistant\Application\AssistantConversationRepository;

final class InMemoryAssistantConversationRepository implements AssistantConversationRepository
{
    /** @var array<string, AssistantConversation> */
    private array $conversations = [];

    public function getOrCreate(?string $publicId): AssistantConversation
    {
        if ($publicId !== null && isset($this->conversations[$publicId])) {
            return $this->conversations[$publicId];
        }

        $conversation = new AssistantConversation(self::uuid());
        $this->conversations[$conversation->publicId] = $conversation;

        return $conversation;
    }

    public function find(string $publicId): ?AssistantConversation
    {
        return $this->conversations[$publicId] ?? null;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
