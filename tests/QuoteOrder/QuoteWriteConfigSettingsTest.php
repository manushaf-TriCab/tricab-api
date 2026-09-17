<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\QuoteOrder;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteMisconfiguredException;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;

final class QuoteWriteConfigSettingsTest extends TestCase
{
    public function test_settingsDefaultWriteDisabled(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'test',
        ]);
        self::assertFalse($settings->quotesOrdersLegacyWriteEnabled);
        self::assertNull($settings->quotesOrdersLegacyWriteDsn);
        self::assertInstanceOf(LegacyQuoteWriteConfig::class, $settings->legacyQuoteWriteConfig());
        self::assertFalse($settings->legacyQuoteWriteConfig()->enabled);
    }

    public function test_settingsFailClosedWhenWriteEnabledWithoutCredentials(): void
    {
        $this->expectException(QuoteWriteMisconfiguredException::class);
        Settings::fromEnvironment([
            'APP_ENV' => 'test',
            'QUOTES_ORDERS_LEGACY_WRITE_ENABLED' => 'true',
            'QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX' => '_dev',
        ]);
    }

    public function test_legacyTcRoEnvDoesNotPopulateWriteConfig(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'test',
            'LEGACY_TC_DSN' => 'mysql:host=ro;dbname=tc',
            'LEGACY_TC_USER' => 'readonly',
            'LEGACY_TC_PASSWORD' => 'secret',
            'QUOTES_ORDERS_LEGACY_READ_ENABLED' => 'true',
        ]);
        self::assertFalse($settings->quotesOrdersLegacyWriteEnabled);
        self::assertNull($settings->quotesOrdersLegacyWriteUser);
        self::assertNull($settings->quotesOrdersLegacyWritePassword);
    }
}
