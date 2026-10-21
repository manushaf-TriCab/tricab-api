<?php

declare(strict_types=1);

namespace TriCab\Api\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\PlatformSettings\AzureSqlPlatformSettingsRepository;

final class PlatformSettingsAzureSqlRepositoryTest extends TestCase
{
    public function test_reads_and_writes_are_scoped_to_app_environment(): void
    {
        $sql = new PlatformSettingsSqlConnection();
        $sql->queueFetch([['Version' => 2, 'OverridesJson' => '{"notifications":{"rfqMailbox":"dev@example.com"}}', 'DefaultsJson' => '{"ai":{"tasks":{}},"notifications":{}}']]);
        $sql->queueFetch([['Version' => 2, 'ChangedAtUtc' => '2026-10-21 01:02:03', 'ActorUserId' => 7, 'Operation' => 'update', 'OverridesJson' => '{}']]);
        $sql->queueFetch([['Version' => 2]]);
        $repository = new AzureSqlPlatformSettingsRepository($sql, $this->settings('development'));

        self::assertSame(2, $repository->current()['version']);
        self::assertCount(1, $repository->history());
        self::assertTrue($repository->replace(2, ['notifications' => ['rfqMailbox' => 'new@example.com']], ['ai' => ['tasks' => []]], 7, 'update'));
        self::assertTrue($sql->committed);
        foreach ($sql->calls as $call) {
            self::assertSame('development', $call['params'][':environment'] ?? null);
        }
        $writes = array_values(array_filter($sql->calls, static fn (array $call): bool => str_starts_with($call['sql'], 'UPDATE') || str_starts_with($call['sql'], 'INSERT')));
        self::assertCount(2, $writes);
        self::assertStringContainsString('[site].[PlatformSettingsHistory]', $writes[1]['sql']);
    }

    public function test_stale_version_rolls_back_without_audit_or_settings_write(): void
    {
        $sql = new PlatformSettingsSqlConnection();
        $sql->queueFetch([['Version' => 3]]);
        $repository = new AzureSqlPlatformSettingsRepository($sql, $this->settings('staging'));
        self::assertFalse($repository->replace(2, [], [], 1, 'update'));
        self::assertTrue($sql->rolledBack);
        self::assertFalse($sql->committed);
        self::assertCount(1, $sql->calls);
        self::assertSame('staging', $sql->calls[0]['params'][':environment']);
    }

    public function test_audit_failure_rolls_back_settings_update(): void
    {
        $sql = new PlatformSettingsSqlConnection();
        $sql->queueFetch([['Version' => 1]]);
        $sql->failSqlContaining = 'INSERT INTO [site].[PlatformSettingsHistory]';
        $repository = new AzureSqlPlatformSettingsRepository($sql, $this->settings('staging'));
        try {
            $repository->replace(1, [], [], 1, 'reset');
            self::fail('Audit failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('audit insert failed', $exception->getMessage());
        }
        self::assertTrue($sql->rolledBack);
        self::assertFalse($sql->committed);
        self::assertStringContainsString('UPDATE [site].[PlatformSettings]', $sql->calls[1]['sql']);
        self::assertStringContainsString('INSERT INTO [site].[PlatformSettingsHistory]', $sql->calls[2]['sql']);
    }

    public function test_only_missing_platform_tables_fall_back_to_unconfigured_defaults(): void
    {
        $sql = new PlatformSettingsSqlConnection();
        $sql->fetchFailure = new RuntimeException("Invalid object name 'site.PlatformSettings'.");
        $repository = new AzureSqlPlatformSettingsRepository($sql, $this->settings('development'));
        self::assertSame(['version' => 0, 'overrides' => []], $repository->current());
        $sql->fetchFailure = new RuntimeException("Invalid object name 'site.PlatformSettingsHistory'.");
        self::assertSame([], $repository->history());
        $sql->fetchFailure = new RuntimeException("Invalid object name 'identity.Users'.");
        try {
            $repository->current();
            self::fail('An unrelated missing table must propagate.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('identity.Users', $exception->getMessage());
        }
        $sql->fetchFailure = new RuntimeException('SQL connection timed out');
        $this->expectExceptionMessage('SQL connection timed out');
        $repository->current();
    }

    private function settings(string $env): Settings
    {
        return Settings::fromEnvironment([
            'APP_ENV' => $env,
            'USER_REPOSITORY' => 'memory',
            'ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION' => 'true',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
        ]);
    }
}

final class PlatformSettingsSqlConnection implements SqlConnection
{
    /** @var list<list<array<string,mixed>>> */
    private array $fetchQueue = [];
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $calls = [];
    public bool $committed = false;
    public bool $rolledBack = false;
    public ?RuntimeException $fetchFailure = null;
    public ?string $failSqlContaining = null;
    private bool $transaction = false;

    /** @param list<array<string,mixed>> $rows */
    public function queueFetch(array $rows): void { $this->fetchQueue[] = $rows; }
    public function fetchAll(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
        if ($this->fetchFailure !== null) { throw $this->fetchFailure; }
        return array_shift($this->fetchQueue) ?? [];
    }
    public function execute(string $sql, array $params = []): void
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
        if ($this->failSqlContaining !== null && str_contains($sql, $this->failSqlContaining)) {
            throw new RuntimeException('audit insert failed');
        }
    }
    public function beginTransaction(): void { $this->transaction = true; }
    public function commit(): void { $this->committed = true; $this->transaction = false; }
    public function rollBack(): void { $this->rolledBack = true; $this->transaction = false; }
    public function inTransaction(): bool { return $this->transaction; }
}
