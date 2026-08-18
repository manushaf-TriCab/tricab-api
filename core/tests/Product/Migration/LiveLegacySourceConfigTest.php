<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

final class LiveLegacySourceConfigTest extends TestCase
{
    public function test_builds_mysql_dsn_from_host_parts_when_dsn_absent(): void
    {
        $config = LiveLegacySourceConfig::fromEnvironment([
            'LEGACY_WP_HOST' => 'wp.example.com',
            'LEGACY_WP_PORT' => '3307',
            'LEGACY_WP_DATABASE' => 'tricab',
            'LEGACY_WP_USER' => 'ro',
            'LEGACY_WP_PASSWORD' => 'secret',
            'LEGACY_WP_SSL_MODE' => 'required',
            'LEGACY_TC_HOST' => 'tc.example.com',
            'LEGACY_TC_DATABASE' => 'tc',
            'LEGACY_TC_USER' => 'ro',
            'LEGACY_TC_PASSWORD' => 'secret',
        ]);

        self::assertTrue($config->wordpressConfigured());
        self::assertSame(
            'mysql:host=wp.example.com;port=3307;dbname=tricab;charset=utf8mb4',
            $config->wordpressDsn,
        );
        self::assertSame('required', $config->wordpressSslMode);
        self::assertTrue($config->tcConfigured());
        self::assertSame(
            'mysql:host=tc.example.com;port=3306;dbname=tc;charset=utf8mb4',
            $config->tcDsn,
        );
        self::assertArrayNotHasKey('wordpressPassword', $config->toPublicArray());
    }

    public function test_explicit_dsn_wins_over_host_parts(): void
    {
        $config = LiveLegacySourceConfig::fromEnvironment([
            'LEGACY_WP_DSN' => 'mysql:host=from-dsn;dbname=tricab;charset=utf8mb4',
            'LEGACY_WP_HOST' => 'ignored.example.com',
            'LEGACY_WP_DATABASE' => 'ignored',
            'LEGACY_WP_USER' => 'ro',
            'LEGACY_WP_PASSWORD' => 'secret',
        ]);

        self::assertSame('mysql:host=from-dsn;dbname=tricab;charset=utf8mb4', $config->wordpressDsn);
    }
}
