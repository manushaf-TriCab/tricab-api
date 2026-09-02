<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Infrastructure\InMemoryAssistantConversationRepository;

final class InMemoryAssistantConversationRepositoryTest extends TestCase
{
    public function test_only_resumes_a_conversation_in_the_same_region_and_visibility_scope(): void
    {
        $repository = new InMemoryAssistantConversationRepository();
        $original = $repository->getOrCreate(null, 'au', AssistantCatalogueVisibility::STAFF);
        $sameContext = $repository->getOrCreate(
            $original->publicId,
            'AU',
            AssistantCatalogueVisibility::STAFF,
        );
        $publicContext = $repository->getOrCreate(
            $original->publicId,
            'AU',
            AssistantCatalogueVisibility::PUBLIC,
        );
        $otherRegion = $repository->getOrCreate(
            $original->publicId,
            'NZ',
            AssistantCatalogueVisibility::STAFF,
        );

        self::assertSame($original, $sameContext);
        self::assertNotSame($original->publicId, $publicContext->publicId);
        self::assertNotSame($original->publicId, $otherRegion->publicId);
    }
}
