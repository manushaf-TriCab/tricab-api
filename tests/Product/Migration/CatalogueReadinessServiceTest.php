<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

final class CatalogueReadinessServiceTest extends TestCase
{
    public function test_from_environment_never_exposes_secrets_in_public_array(): void
    {
        $config = LiveLegacySourceConfig::fromEnvironment([
            'LEGACY_WP_DSN' => 'mysql:host=legacy;dbname=wp',
            'LEGACY_WP_USER' => 'reader',
            'LEGACY_WP_PASSWORD' => 'super-secret',
            'LEGACY_SOURCE_WAIVE_WP' => 'false',
            'LEGACY_SOURCE_WAIVE_TC' => 'true',
            'LEGACY_SOURCE_WAIVE_FILES' => 'true',
            'PHASE12_PILOT_APPROVED' => 'true',
        ]);
        self::assertTrue($config->wordpressConfigured());
        $public = $config->toPublicArray();
        self::assertTrue($public['wordpressConfigured']);
        self::assertArrayNotHasKey('wordpressPassword', $public);
        self::assertArrayNotHasKey('wordpressUser', $public);
        self::assertArrayNotHasKey('wordpressDsn', $public);
        self::assertStringNotContainsString('super-secret', json_encode($public, JSON_THROW_ON_ERROR));
    }
}
