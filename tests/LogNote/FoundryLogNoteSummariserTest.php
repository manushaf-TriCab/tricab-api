<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LogNote;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Config\Settings;
use TriCab\Api\LogNote\Infrastructure\FoundryLogNoteSummariser;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

final class FoundryLogNoteSummariserTest extends TestCase
{
    public function test_sends_the_configured_effort_and_room_for_thinking(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => '{"content":[{"type":"thinking","thinking":""},{"type":"text","text":"Called the customer."}]}'],
        ];

        $summary = $this->summariser($http, 'low')->summarise('rang customer re order');

        self::assertSame('Called the customer.', $summary, 'reads the text block, not the thinking block before it');
        $payload = $http->calls[0]['payload'] ?? null;
        self::assertIsArray($payload);
        self::assertSame(['effort' => 'low'], $payload['output_config']);
        self::assertSame(4_000, $payload['max_tokens']);
    }

    public function test_sends_no_effort_when_none_is_configured(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"Summary."}]}'],
        ];

        $this->summariser($http, '')->summarise('note');

        $payload = $http->calls[0]['payload'] ?? null;
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('output_config', $payload);
    }

    private function summariser(FakeJsonHttpClient $http, string $effort): FoundryLogNoteSummariser
    {
        return new FoundryLogNoteSummariser(
            http: $http,
            accessTokens: new class implements FoundryAccessTokenProvider {
                public function accessToken(): string
                {
                    throw new \LogicException('API-key test must not request a token.');
                }
            },
            endpoint: 'https://tricab-ai2.services.ai.azure.com',
            deployment: 'claude-sonnet-5-5',
            authMode: Settings::FOUNDRY_AUTH_MODE_API_KEY,
            apiKey: 'test-key',
            effort: $effort,
        );
    }
}
