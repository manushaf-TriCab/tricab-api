<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

final class ProductPricingUomEndpointsTest extends TestCase
{
    public function test_lookups_include_pricing_units(): void
    {
        $app = $this->createApp();
        $lookups = TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-lookups')))['data'];

        self::assertNotEmpty($lookups['pricingUnits']);
        self::assertSame('M', $lookups['pricingUnits'][0]['code']);
    }

    public function test_set_default_unit_and_upsert_with_explicit_unit(): void
    {
        $this->markTestSkipped('Requires CreateProduct DI; covered by ProductPricingUomServiceTest.');
    }

    public function test_invalid_pricing_unit_returns_422(): void
    {
        $app = $this->createApp();
        $productPublicId = '44444444-4444-4444-8444-444444444444';

        $response = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/admin/products/' . $productPublicId . '/pricing/default-unit',
            ['pricingUnitPublicId' => '00000000-0000-0000-0000-000000000099'],
        ));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('INVALID_PRICING_UNIT', TestAppFactory::json($response)['error']['code']);
    }

    /** @return App<ContainerInterface> */
    private function createApp(): App
    {
        return TestAppFactory::create([]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
