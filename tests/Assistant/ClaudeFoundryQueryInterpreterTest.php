<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\Foundry\ClaudeFoundryQueryInterpreter;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Prompt\VersionedPrompt;
use TriCab\Api\Config\Settings;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

final class ClaudeFoundryQueryInterpreterTest extends TestCase
{
    public function test_uses_versioned_prompt_and_discards_ungrounded_search_terms(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'canonicalIntent' => 'Fire Resistant',
                    'searchTerms' => ['Fire Resistant', 'FR', 'Invented Super Cable'],
                    'clarificationQuestion' => 'What voltage and core count do you need?',
                    'confidence' => 0.95,
                ], JSON_THROW_ON_ERROR),
            ]],
        ], JSON_THROW_ON_ERROR);
        $prompt = new VersionedPrompt('product-assistant-query', 'v1', 'QUERY PROMPT V1');
        $interpreter = new ClaudeFoundryQueryInterpreter(
            http: $http,
            accessTokens: $this->unusedAccessTokenProvider(),
            endpoint: 'https://tricab-ai2.services.ai.azure.com',
            deployment: 'claude-opus-4-8',
            authMode: Settings::FOUNDRY_AUTH_MODE_API_KEY,
            prompt: $prompt,
            apiKey: 'test-key',
        );

        $plan = $interpreter->interpret('What fire rated cables do you have?');

        self::assertSame('Fire Resistant', $plan->canonicalIntent);
        self::assertSame(['Fire Resistant', 'FR'], $plan->searchTerms);
        self::assertSame('product-assistant-query-v1', $plan->instructionVersion);
        $payload = $http->calls[0]['payload'];
        self::assertIsArray($payload);
        self::assertSame('QUERY PROMPT V1', $payload['system']);
        self::assertSame('test-key', $http->calls[0]['headers']['x-api-key']);
    }

    private function unusedAccessTokenProvider(): FoundryAccessTokenProvider
    {
        return new class implements FoundryAccessTokenProvider {
            public function accessToken(): string
            {
                throw new \LogicException('API-key authentication must not request an Entra token.');
            }
        };
    }
}
