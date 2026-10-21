<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\PlatformSettings;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\PlatformSettings\InMemoryPlatformSettingsRepository;
use TriCab\Api\PlatformSettings\PlatformSettingsRuntimeCache;
use TriCab\Api\PlatformSettings\PlatformSettingsService;

final class PlatformSettingsRuntimeCacheTest extends TestCase
{
    private const SAVED = ['notifications' => ['nprMailTestRecipient' => 'saved@example.com']];
    private const NEWER = ['notifications' => ['nprMailTestRecipient' => 'newer@example.com']];

    private ArrayApplicationCache $store;
    private int $now = 1_800_000_000;
    private int $loads = 0;

    protected function setUp(): void
    {
        $this->store = new ArrayApplicationCache();
    }

    public function test_the_first_read_loads_the_database_and_keeps_a_copy(): void
    {
        $cache = $this->cache();

        self::assertSame(self::SAVED, $cache->overrides($this->load(self::SAVED), true));
        self::assertSame(self::SAVED, $cache->overrides($this->load(self::NEWER), true));
        self::assertSame(1, $this->loads);
    }

    public function test_a_stale_copy_is_checked_again_when_refreshing(): void
    {
        $cache = $this->cache();
        $cache->overrides($this->load(self::SAVED), true);

        $this->now += PlatformSettingsRuntimeCache::REFRESH_SECONDS;

        self::assertSame(self::NEWER, $cache->overrides($this->load(self::NEWER), true));
        self::assertSame(2, $this->loads);
    }

    public function test_without_refresh_any_copy_is_used_and_the_database_left_alone(): void
    {
        $cache = $this->cache();
        $cache->overrides($this->load(self::SAVED), true);

        $this->now += 30 * 86400 - 1;

        self::assertSame(self::SAVED, $cache->overrides($this->load(self::NEWER), false));
        self::assertSame(1, $this->loads);
    }

    public function test_without_refresh_the_database_is_read_when_there_is_no_copy(): void
    {
        self::assertSame(self::SAVED, $this->cache()->overrides($this->load(self::SAVED), false));
        self::assertSame(1, $this->loads);
    }

    public function test_a_failed_check_keeps_the_last_good_copy(): void
    {
        $cache = $this->cache();
        $cache->overrides($this->load(self::SAVED), true);
        $this->now += PlatformSettingsRuntimeCache::REFRESH_SECONDS;

        self::assertSame(self::SAVED, $cache->overrides(static fn (): array => throw new RuntimeException('database resuming'), true));
    }

    public function test_a_failed_read_with_no_copy_is_not_hidden(): void
    {
        $this->expectException(RuntimeException::class);

        $this->cache()->overrides(static fn (): array => throw new RuntimeException('database resuming'), true);
    }

    public function test_a_malformed_entry_is_ignored(): void
    {
        $this->store->set('platform-settings:runtime:production', ['storedAt' => 'yesterday', 'overrides' => 'x'], 60);

        self::assertSame(self::SAVED, $this->cache()->overrides($this->load(self::SAVED), false));
    }

    public function test_environments_do_not_share_a_copy(): void
    {
        $this->cache()->store(self::SAVED);

        $development = new PlatformSettingsRuntimeCache($this->store, 'development', fn (): int => $this->now);

        self::assertSame(self::NEWER, $development->overrides($this->load(self::NEWER), false));
    }

    public function test_saving_puts_the_change_in_the_cache_straight_away(): void
    {
        $cache = $this->cache();
        $cache->store(self::SAVED);
        $service = new PlatformSettingsService(new InMemoryPlatformSettingsRepository(), $this->settings(), $cache);

        $service->replace(0, self::NEWER, 1);

        self::assertSame(
            'newer@example.com',
            $this->recipient($cache->overrides(static fn (): array => throw new RuntimeException('not read'), false)),
        );

        $service->reset(1, 'notifications', 1);

        self::assertSame(
            'baseline@example.com',
            $this->recipient($cache->overrides(static fn (): array => throw new RuntimeException('not read'), false)),
        );
    }

    private function cache(): PlatformSettingsRuntimeCache
    {
        return new PlatformSettingsRuntimeCache($this->store, 'production', fn (): int => $this->now);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return \Closure(): array<string,mixed>
     */
    private function load(array $overrides): \Closure
    {
        return function () use ($overrides): array {
            ++$this->loads;

            return $overrides;
        };
    }

    /** @param array<string,mixed> $overrides */
    private function recipient(array $overrides): mixed
    {
        /** @var array{notifications: array<string, mixed>} $overrides */
        return $overrides['notifications']['nprMailTestRecipient'] ?? null;
    }

    private function settings(): Settings
    {
        return Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'NPR_MAIL_TEST_RECIPIENT' => 'baseline@example.com',
        ]);
    }
}
