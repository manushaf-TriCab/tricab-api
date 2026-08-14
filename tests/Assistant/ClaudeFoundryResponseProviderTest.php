<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\GroundedProduct;
use TriCab\Api\Assistant\Infrastructure\Foundry\ClaudeFoundryResponseProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;
use TriCab\Api\Assistant\Infrastructure\Prompt\VersionedPrompt;
use TriCab\Api\Config\Settings;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

final class ClaudeFoundryResponseProviderTest extends TestCase
{
    public function test_sends_a_grounded_messages_request_with_api_key_authentication(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'The KL cable is listed for fixed industrial wiring.'],
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = new ClaudeFoundryResponseProvider(
            http: $http,
            accessTokens: $this->unusedAccessTokenProvider(),
            endpoint: 'https://tricab-ai2.services.ai.azure.com',
            deployment: 'claude-opus-4-8',
            authMode: Settings::FOUNDRY_AUTH_MODE_API_KEY,
            prompt: $this->prompt(),
            apiKey: 'test-key',
        );

        $result = $provider->generate($this->grounding());

        self::assertSame('foundry-claude', $result->provider);
        self::assertSame('product-assistant-response-v2', $result->instructionVersion);
        self::assertSame('The KL cable is listed for fixed industrial wiring.', $result->text);
        self::assertCount(1, $http->calls);

        $call = $http->calls[0];
        self::assertSame(
            'https://tricab-ai2.services.ai.azure.com/anthropic/v1/messages',
            $call['url'],
        );
        self::assertSame('json', $call['kind']);
        self::assertSame('2023-06-01', $call['headers']['anthropic-version']);
        self::assertSame('test-key', $call['headers']['x-api-key']);
        self::assertArrayNotHasKey('Authorization', $call['headers']);
        self::assertIsArray($call['payload']);
        self::assertArrayHasKey('model', $call['payload']);
        self::assertSame('claude-opus-4-8', $call['payload']['model']);

        $messages = $call['payload']['messages'];
        self::assertIsArray($messages);
        self::assertIsArray($messages[0]);
        self::assertIsString($messages[0]['content']);
        self::assertStringContainsString('AUTHORIZED_CATALOGUE_DATA', $messages[0]['content']);
        self::assertStringContainsString('"catalogueVisibility":"public"', $messages[0]['content']);
        self::assertStringContainsString('KL Industrial Cable', $messages[0]['content']);
        self::assertStringNotContainsString('thumbnailUrl', $messages[0]['content']);
    }

    public function test_uses_an_entra_bearer_token_for_managed_identity_authentication(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"content":[{"type":"text","text":"Grounded answer"}]}';
        $accessTokens = new class implements FoundryAccessTokenProvider {
            public function accessToken(): string
            {
                return 'entra-token';
            }
        };
        $provider = new ClaudeFoundryResponseProvider(
            http: $http,
            accessTokens: $accessTokens,
            endpoint: 'https://tricab-ai2.services.ai.azure.com',
            deployment: 'claude-opus-4-8',
            authMode: Settings::FOUNDRY_AUTH_MODE_MANAGED_IDENTITY,
            prompt: $this->prompt(),
        );

        $provider->generate($this->grounding());

        self::assertSame('Bearer entra-token', $http->calls[0]['headers']['Authorization']);
        self::assertArrayNotHasKey('x-api-key', $http->calls[0]['headers']);
    }

    public function test_rejects_non_success_responses_without_exposing_the_response_body(): void
    {
        $http = new FakeJsonHttpClient();
        $http->status = 401;
        $http->body = 'secret provider diagnostic';
        $provider = new ClaudeFoundryResponseProvider(
            http: $http,
            accessTokens: $this->unusedAccessTokenProvider(),
            endpoint: 'https://tricab-ai2.services.ai.azure.com',
            deployment: 'claude-opus-4-8',
            authMode: Settings::FOUNDRY_AUTH_MODE_API_KEY,
            prompt: $this->prompt(),
            apiKey: 'test-key',
        );

        try {
            $provider->generate($this->grounding());
            self::fail('Expected the provider to reject the response.');
        } catch (FoundryProviderUnavailable $exception) {
            self::assertStringNotContainsString('secret provider diagnostic', $exception->getMessage());
            self::assertSame('Foundry Messages request was rejected.', $exception->getMessage());
        }
    }

    public function test_rejects_a_success_response_without_text_content(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"content":[{"type":"tool_use"}]}';
        $provider = new ClaudeFoundryResponseProvider(
            http: $http,
            accessTokens: $this->unusedAccessTokenProvider(),
            endpoint: 'https://tricab-ai2.services.ai.azure.com',
            deployment: 'claude-opus-4-8',
            authMode: Settings::FOUNDRY_AUTH_MODE_API_KEY,
            prompt: $this->prompt(),
            apiKey: 'test-key',
        );

        $this->expectException(FoundryProviderUnavailable::class);
        $this->expectExceptionMessage('did not contain text');

        $provider->generate($this->grounding());
    }

    private function grounding(): AssistantGrounding
    {
        return new AssistantGrounding(
            userMessage: 'Is KL suitable for industrial wiring?',
            searchQuery: 'KL',
            regionCode: 'AU',
            products: [new GroundedProduct(
                publicId: 'product-1',
                productName: 'KL Industrial Cable',
                productCode: 'KL-001',
                slug: 'kl-industrial-cable',
                seriesName: 'KL',
                categoryName: 'Industrial Cable',
                summary: 'A published cable range.',
                applications: ['Fixed industrial wiring'],
                specifications: [['name' => 'Voltage', 'value' => '0.6/1 kV']],
                thumbnailUrl: 'https://internal.example/product.png',
            )],
        );
    }

    private function unusedAccessTokenProvider(): FoundryAccessTokenProvider
    {
        return new class implements FoundryAccessTokenProvider {
            public function accessToken(): string
            {
                throw new \LogicException('Token provider should not be called for API key auth.');
            }
        };
    }

    private function prompt(): VersionedPrompt
    {
        return new VersionedPrompt(
            name: 'product-assistant-response',
            version: 'v2',
            content: 'Use only AUTHORIZED_CATALOGUE_DATA.',
        );
    }
}
