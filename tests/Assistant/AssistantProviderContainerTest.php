<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantConversationRepository;
use TriCab\Api\Assistant\Application\AssistantQueryInterpreter;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\AssistantTurnTrace;
use TriCab\Api\Assistant\Application\ProductAssistantService;
use TriCab\Api\Assistant\Infrastructure\AgentAssistantQueryInterpreter;
use TriCab\Api\Assistant\Infrastructure\AzureSqlAssistantConversationRepository;
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
        self::assertInstanceOf(
            AgentAssistantQueryInterpreter::class,
            $container->get(AssistantQueryInterpreter::class),
        );
    }

    public function test_the_service_and_live_providers_share_one_turn_trace(): void
    {
        $container = ContainerFactory::create(Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'FOUNDRY_ENABLED' => 'true',
            'FOUNDRY_ENDPOINT' => 'https://tricab-ai2.services.ai.azure.com',
            'FOUNDRY_MODEL_DEPLOYMENT' => 'claude-sonnet-5',
            'FOUNDRY_AUTH_MODE' => 'api-key',
            'FOUNDRY_API_KEY' => 'test-key',
        ]));

        $trace = $container->get(AssistantTurnTrace::class);
        $service = $container->get(ProductAssistantService::class);
        $fallback = $container->get(AssistantResponseProvider::class);
        self::assertInstanceOf(ProductAssistantService::class, $service);
        self::assertInstanceOf(FallbackAssistantResponseProvider::class, $fallback);
        $primary = self::property($fallback, 'primary');
        self::assertIsObject($primary);

        self::assertSame($trace, self::property($service, 'trace'));
        self::assertSame($trace, self::property($fallback, 'trace'));
        self::assertSame($trace, self::property($primary, 'trace'));
    }

    public function test_binds_durable_conversations_for_the_azure_sql_runtime(): void
    {
        $container = ContainerFactory::create(Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'azure-sql',
            'DB_AUTH_MODE' => 'sql-login',
            'DB_HOST' => 'test.database.windows.net',
            'DB_DATABASE' => 'tricab-test',
            'DB_USERNAME' => 'test',
            'DB_PASSWORD' => 'test',
            'GUEST_JWT_ISSUER' => 'https://api.test',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'test-secret-that-is-at-least-32-bytes',
        ]));

        self::assertInstanceOf(
            AzureSqlAssistantConversationRepository::class,
            $container->get(AssistantConversationRepository::class),
        );
    }

    private static function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
