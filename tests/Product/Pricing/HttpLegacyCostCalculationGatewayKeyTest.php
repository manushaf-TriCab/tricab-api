<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Pricing\HttpLegacyCostCalculationGateway;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

/** The Cost API answers only calls carrying its key, so the gateway sends it on every lookup. */
final class HttpLegacyCostCalculationGatewayKeyTest extends TestCase
{
    public function test_the_key_goes_out_as_the_cost_api_header(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"success":false,"error":"Forbidden"}';

        (new HttpLegacyCostCalculationGateway($http, 'https://tricab.com/cost-api/', 'secret-key'))->resolveCost('XL-PAXN/5C120OR', '2507', '130');

        self::assertNotSame([], $http->calls);
        self::assertSame('secret-key', $http->calls[0]['headers']['X-Cost-Api-Key'] ?? null);
    }

    public function test_no_key_sends_no_header(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"success":false,"error":"Forbidden"}';

        (new HttpLegacyCostCalculationGateway($http, 'https://tricab.com/cost-api/'))->resolveCost('XL-PAXN/5C120OR', '2507', '130');

        self::assertArrayNotHasKey('X-Cost-Api-Key', $http->calls[0]['headers']);
    }

    public function test_settings_read_the_key_the_rfq_parser_uses(): void
    {
        $env = ['APP_ENV' => 'development', 'TRICAB_COST_API_KEY' => ' secret-key '];

        self::assertSame('secret-key', Settings::fromEnvironment($env)->legacyCostApiKey);
        self::assertSame('', Settings::fromEnvironment(['APP_ENV' => 'development'])->legacyCostApiKey);
    }
}
