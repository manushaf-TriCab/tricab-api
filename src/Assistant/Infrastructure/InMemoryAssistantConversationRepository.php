<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantConversation;
use TriCab\Api\Assistant\Application\AssistantConversationRepository;
use TriCab\Api\Assistant\Application\AssistantConversationTurn;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;

final class InMemoryAssistantConversationRepository implements AssistantConversationRepository
{
    /** @var array<string, AssistantConversation> */
    private array $conversations = [];

    public function getOrCreate(
        ?string $publicId,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): AssistantConversation {
        $normalizedRegion = self::normalizeRegion($regionCode);
        if ($publicId !== null && isset($this->conversations[$publicId])) {
            $existing = $this->conversations[$publicId];
            if (
                $existing->regionCode === $normalizedRegion
                && $existing->catalogueVisibility === $catalogueVisibility->value
            ) {
                return $existing;
            }
        }

        $conversation = new AssistantConversation(
            self::uuid(),
            $normalizedRegion,
            $catalogueVisibility->value,
        );
        $this->conversations[$conversation->publicId] = $conversation;

        return $conversation;
    }

    public function find(string $publicId): ?AssistantConversation
    {
        return $this->conversations[$publicId] ?? null;
    }

    public function appendTurn(AssistantConversation $conversation, AssistantConversationTurn $turn): void
    {
        $conversation->append($turn);
    }

    public function persistsAcrossRequests(): bool
    {
        return false;
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

    private static function normalizeRegion(?string $regionCode): ?string
    {
        $normalized = $regionCode === null ? '' : strtoupper(trim($regionCode));

        return $normalized === '' ? null : $normalized;
    }
}
