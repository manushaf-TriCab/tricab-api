<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ai;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ai\HttpFinanceAiClient;
use TriCab\Api\Finance\Ai\UndeployedFinanceAiClient;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;

/**
 * How the settings choose the client: the loopback client only when switched on AND a
 * token is set, and never one pointed off this machine.
 */
final class FinanceAiWiringTest extends TestCase
{
    /**
     * @param array<string, string> $env
     */
    private function client(array $env): FinanceAiClient
    {
        $container = TestAppFactory::create($env)->getContainer();
        $client = $container->get(FinanceAiClient::class);
        self::assertInstanceOf(FinanceAiClient::class, $client);

        return $client;
    }

    public function test_by_default_the_service_is_off(): void
    {
        self::assertInstanceOf(UndeployedFinanceAiClient::class, $this->client([]));
    }

    public function test_enabled_with_an_empty_token_is_still_off(): void
    {
        self::assertInstanceOf(UndeployedFinanceAiClient::class, $this->client(['FINANCE_AI_ENABLED' => 'true']));
        self::assertInstanceOf(UndeployedFinanceAiClient::class, $this->client(['FINANCE_AI_ENABLED' => 'true', 'FINANCE_AI_INTERNAL_TOKEN' => '   ']));
    }

    public function test_a_token_alone_does_not_turn_it_on(): void
    {
        self::assertInstanceOf(UndeployedFinanceAiClient::class, $this->client(['FINANCE_AI_INTERNAL_TOKEN' => 't']));
    }

    public function test_enabled_with_a_token_uses_the_loopback_client(): void
    {
        self::assertInstanceOf(HttpFinanceAiClient::class, $this->client(['FINANCE_AI_ENABLED' => 'true', 'FINANCE_AI_INTERNAL_TOKEN' => 't']));
    }

    public function test_only_the_exact_text_true_turns_it_on_as_startup_sh_reads_it(): void
    {
        foreach (['TRUE', 'True', 'yes', 'on', '1', ' true', 'false', ''] as $value) {
            self::assertInstanceOf(
                UndeployedFinanceAiClient::class,
                $this->client(['FINANCE_AI_ENABLED' => $value, 'FINANCE_AI_INTERNAL_TOKEN' => 't']),
                '"' . $value . '"',
            );
        }
        self::assertInstanceOf(HttpFinanceAiClient::class, $this->client(['FINANCE_AI_ENABLED' => 'true', 'FINANCE_AI_INTERNAL_TOKEN' => 't']));
    }

    public function test_a_base_url_off_this_machine_is_refused_when_enabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FINANCE_AI_BASE_URL must point at the loopback interface');

        TestAppFactory::create(['FINANCE_AI_ENABLED' => 'true', 'FINANCE_AI_INTERNAL_TOKEN' => 't', 'FINANCE_AI_BASE_URL' => 'https://example.com']);
    }

    public function test_a_base_url_off_this_machine_does_nothing_while_it_is_off(): void
    {
        self::assertInstanceOf(UndeployedFinanceAiClient::class, $this->client(['FINANCE_AI_BASE_URL' => 'https://example.com']));
    }
}
