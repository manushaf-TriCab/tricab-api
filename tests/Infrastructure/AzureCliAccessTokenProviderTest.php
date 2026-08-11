<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenException;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Authentication\AzureCliProcessRunner;

final class AzureCliAccessTokenProviderTest extends TestCase
{
    protected function setUp(): void
    {
        AzureCliAccessTokenProvider::resetProcessCache();
    }

    protected function tearDown(): void
    {
        AzureCliAccessTokenProvider::resetProcessCache();
    }

    public function test_missing_azure_cli_throws_descriptive_exception(): void
    {
        $provider = new AzureCliAccessTokenProvider('/definitely/missing/az');

        $this->expectException(AzureCliAccessTokenException::class);
        $this->expectExceptionMessage('Azure CLI is unavailable');

        $provider->getAccessToken();
    }

    public function test_settings_recognise_entra_cli_mode(): void
    {
        $settings = Settings::fromEnvironment([
            'DB_AUTH_MODE' => 'entra-cli',
            'USER_REPOSITORY' => 'azure-sql',
            'AZURE_CLI_BINARY' => 'C:/Program Files/Microsoft SDKs/Azure/CLI2/wbin/az.cmd',
        ]);

        self::assertTrue($settings->usesEntraCliAuth());
        self::assertFalse($settings->usesSqlLoginAuth());
        self::assertSame(Settings::DB_AUTH_MODE_ENTRA_CLI, $settings->dbAuthMode);
        self::assertSame(
            'C:/Program Files/Microsoft SDKs/Azure/CLI2/wbin/az.cmd',
            $settings->azureCliBinary,
        );
    }

    public function test_cache_hit_does_not_invoke_cli(): void
    {
        $runner = new RecordingAzureCliProcessRunner();
        $now = 1_700_000_000;
        $runner->queueJson(self::tokenPayload('tok-1', $now + 3600));
        $provider = new AzureCliAccessTokenProvider('az', $runner, null, static fn (): int => $now);

        self::assertSame('tok-1', $provider->getAccessToken());
        self::assertSame('tok-1', $provider->getAccessToken());
        self::assertCount(1, $runner->commands);
        self::assertSame('get-access-token', $runner->commands[0][2] ?? null);
        self::assertNotContains('--version', $runner->commands[0]);
        self::assertNotContains('show', $runner->commands[0]);
    }

    public function test_near_expiry_refreshes_token(): void
    {
        $runner = new RecordingAzureCliProcessRunner();
        $now = 1_700_000_000;
        $runner->queueJson(self::tokenPayload('tok-old', $now + 30));
        $runner->queueJson(self::tokenPayload('tok-new', $now + 3600));
        $provider = new AzureCliAccessTokenProvider('az', $runner, null, static fn (): int => $now);

        self::assertSame('tok-old', $provider->getAccessToken());
        self::assertSame('tok-new', $provider->getAccessToken());
        self::assertCount(2, $runner->commands);
    }

    public function test_expired_token_is_not_reused_after_cli_failure(): void
    {
        $runner = new RecordingAzureCliProcessRunner();
        $now = 1_700_000_000;
        $runner->queueJson(self::tokenPayload('tok-old', $now + 30));
        $runner->queue([1, '', 'failed']);
        $provider = new AzureCliAccessTokenProvider('az', $runner, null, static fn (): int => $now);

        self::assertSame('tok-old', $provider->getAccessToken());
        try {
            $provider->getAccessToken();
            self::fail('Expected AzureCliAccessTokenException');
        } catch (AzureCliAccessTokenException) {
        }

        $runner->queueJson(self::tokenPayload('tok-new', $now + 3600));
        self::assertSame('tok-new', $provider->getAccessToken());
    }

    public function test_invalid_cli_json_throws(): void
    {
        $runner = new RecordingAzureCliProcessRunner();
        $runner->queue([0, 'not-json', '']);
        $provider = new AzureCliAccessTokenProvider('az', $runner);

        $this->expectException(AzureCliAccessTokenException::class);
        $this->expectExceptionMessage('invalid access-token response');
        $provider->getAccessToken();
    }

    public function test_cli_failure_throws(): void
    {
        $runner = new RecordingAzureCliProcessRunner();
        $runner->queue([1, '', 'no']);
        $provider = new AzureCliAccessTokenProvider('az', $runner);

        $this->expectException(AzureCliAccessTokenException::class);
        $this->expectExceptionMessage('Failed to acquire');
        $provider->getAccessToken();
    }

    /**
     * @return array{accessToken: string, expires_on: int, expiresOn: string}
     */
    private static function tokenPayload(string $token, int $expiresOn): array
    {
        return [
            'accessToken' => $token,
            'expires_on' => $expiresOn,
            'expiresOn' => gmdate('Y-m-d H:i:s', $expiresOn),
            'tokenType' => 'Bearer',
        ];
    }
}

final class RecordingAzureCliProcessRunner implements AzureCliProcessRunner
{
    /** @var list<array{0: int, 1: string, 2: string}> */
    private array $queue = [];

    /** @var list<list<string>> */
    public array $commands = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function queueJson(array $payload): void
    {
        $this->queue[] = [0, json_encode($payload, JSON_THROW_ON_ERROR), ''];
    }

    /**
     * @param array{0: int, 1: string, 2: string} $result
     */
    public function queue(array $result): void
    {
        $this->queue[] = $result;
    }

    public function run(array $command): array
    {
        $this->commands[] = $command;

        return array_shift($this->queue) ?? [1, '', ''];
    }
}
