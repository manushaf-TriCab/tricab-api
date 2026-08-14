<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Infrastructure\DeterministicAssistantResponseProvider;
use TriCab\Api\Assistant\Infrastructure\FallbackAssistantResponseProvider;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;

final class AssistantProviderContainerTest extends TestCase
{
    public function test_binds_the_deterministic_provider_when_foundry_is_disabled(): void
    {
        $container = ContainerFactory::create(Settings::fromEnvironment([
            'APP_ENV' => 'development',
        ]));

        self::assertInstanceOf(
            DeterministicAssistantResponseProvider::class,
            $container->get(AssistantResponseProvider::class),
        );
    }

    public function test_binds_the_guarded_live_provider_when_foundry_is_enabled(): void
    {
        $container = ContainerFactory::create(Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'FOUNDRY_ENABLED' => 'true',
            'FOUNDRY_ENDPOINT' => 'https://tricab-ai2.services.ai.azure.com',
            'FOUNDRY_MODEL_DEPLOYMENT' => 'claude-opus-4-8',
            'FOUNDRY_AUTH_MODE' => 'api-key',
            'FOUNDRY_API_KEY' => 'test-key',
        ]));

        self::assertInstanceOf(
            FallbackAssistantResponseProvider::class,
            $container->get(AssistantResponseProvider::class),
        );
    }
}
