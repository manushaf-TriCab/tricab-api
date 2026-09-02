<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantConversation;
use TriCab\Api\Assistant\Application\AssistantConversationTurn;
use TriCab\Api\Assistant\Infrastructure\AzureSqlAssistantConversationRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;

final class AzureSqlAssistantConversationRepositoryTest extends TestCase
{
    public function test_hydrates_prior_turns_in_order(): void
    {
        $db = new FakeSqlConnection([
            [
                'ConversationPublicId' => '11111111-1111-4111-8111-111111111111',
                'RegionCode' => 'AU',
                'CatalogueVisibility' => 'public',
                'UserMessage' => 'Find a 10 mm2 cable.',
                'AssistantMessage' => 'How many cores?',
            ],
            [
                'ConversationPublicId' => '11111111-1111-4111-8111-111111111111',
                'RegionCode' => 'AU',
                'CatalogueVisibility' => 'public',
                'UserMessage' => 'Four cores.',
                'AssistantMessage' => 'Do you need an earth?',
            ],
        ]);
        $repository = new AzureSqlAssistantConversationRepository($db);

        $conversation = $repository->find('11111111-1111-4111-8111-111111111111');

        self::assertNotNull($conversation);
        self::assertCount(2, $conversation->turns());
        self::assertSame('Four cores.', $conversation->turns()[1]->userMessage);
        self::assertSame('AU', $conversation->regionCode);
        self::assertTrue($repository->persistsAcrossRequests());
    }

    public function test_creates_a_new_conversation_when_the_requested_id_is_unknown(): void
    {
        $db = new FakeSqlConnection([]);
        $repository = new AzureSqlAssistantConversationRepository($db);

        $conversation = $repository->getOrCreate(
            '11111111-1111-4111-8111-111111111111',
            'au',
            AssistantCatalogueVisibility::PUBLIC,
        );

        self::assertNotSame('11111111-1111-4111-8111-111111111111', $conversation->publicId);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $conversation->publicId);
        self::assertCount(2, $db->calls);
        self::assertStringContainsString('INSERT INTO [product].[AssistantConversations]', $db->calls[1]['sql']);
        self::assertSame($conversation->publicId, $db->calls[1]['params'][':publicId']);
        self::assertSame('AU', $db->calls[1]['params'][':regionCode']);
    }

    public function test_appends_the_turn_with_distinct_sqlsrv_parameters(): void
    {
        $db = new FakeSqlConnection();
        $repository = new AzureSqlAssistantConversationRepository($db);
        $conversation = new AssistantConversation('11111111-1111-4111-8111-111111111111');
        $turn = new AssistantConversationTurn('No, 16 mm2.', 'Updated to 16 mm2.');

        $repository->appendTurn($conversation, $turn);

        self::assertSame([$turn], $conversation->turns());
        self::assertCount(1, $db->calls);
        self::assertSame(
            $conversation->publicId,
            $db->calls[0]['params'][':turnConversationPublicId'],
        );
        self::assertSame(
            $conversation->publicId,
            $db->calls[0]['params'][':touchConversationPublicId'],
        );
        self::assertArrayNotHasKey(':publicId', $db->calls[0]['params']);
    }
}
