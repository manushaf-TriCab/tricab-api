<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\App;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCodeSearchRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchResult;
use TriCab\Core\Product\Contract\ProductCodeSearchRepository;

final class PublicProductCodeSearchEndpointTest extends TestCase
{
    public function test_facet_and_range_query_returns_matching_codes(): void
    {
        $app = $this->appWithCodes();

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/product-codes/search?isLszh=true&crossSectionMm2Min=4&crossSectionMm2Max=25&coreCountMin=4',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        /** @var array{items: list<array<string, mixed>>, pagination: array<string, mixed>} $data */
        $data = $payload['data'];

        self::assertSame(['BHA-PATN/4C4RE', 'BHA-PATN/7C4RE'], array_column($data['items'], 'codeValue'));
        self::assertSame(2, $data['pagination']['totalItems']);
    }

    public function test_segments_are_exposed_individually(): void
    {
        $app = $this->appWithCodes();

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/product-codes/search?styleCodes=BH&coreCount=4'));
        /** @var array{data: array{items: list<array<string, mixed>>}} $payload */
        $payload = TestAppFactory::json($response);
        $item = $payload['data']['items'][0];

        self::assertSame('BHA-PATN/4C4RE', $item['codeValue']);
        self::assertSame('BH', $item['styleCode']);
        self::assertSame('MAKE', $item['stockStatus']);
        // JSON renders 4.0 as 4, so compare numerically rather than by type.
        self::assertEqualsWithDelta(4.0, $item['crossSectionMm2'], 0.001);
        /** @var array<string, mixed> $segments */
        $segments = $item['segments'];
        self::assertSame(4, $segments['coreCount']);
        self::assertSame('C', $segments['coreTypeCode']);
        self::assertSame('RE', $segments['jacketColour']);
        // No loaded variant behind this code, and that is not an error.
        self::assertNull($item['productSlug']);
    }

    public function test_an_unknown_facet_value_excludes_styles_with_no_stated_value(): void
    {
        $app = $this->appWithCodes();

        // BV has no IS-LSZH row at all: unknown, so it matches neither true nor false.
        $trueResponse = $app->handle(TestAppFactory::request('GET', '/api/v1/product-codes/search?isLszh=true'));
        $falseResponse = $app->handle(TestAppFactory::request('GET', '/api/v1/product-codes/search?isLszh=false'));

        /** @var array{data: array{items: list<array<string, mixed>>}} $truePayload */
        $truePayload = TestAppFactory::json($trueResponse);
        /** @var array{data: array{items: list<array<string, mixed>>}} $falsePayload */
        $falsePayload = TestAppFactory::json($falseResponse);

        self::assertNotContains('BVA-CAXD/14C4BK', array_column($truePayload['data']['items'], 'codeValue'));
        self::assertSame([], $falsePayload['data']['items']);
    }

    public function test_an_empty_query_string_is_rejected_rather_than_paging_the_whole_index(): void
    {
        $app = $this->appWithCodes();

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/product-codes/search'));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($payload['success']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('VALIDATION_FAILED', $error['code']);
    }

    public function test_all_true_pages_the_whole_index(): void
    {
        $app = $this->appWithCodes();

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/product-codes/search?all=true&pageSize=2'));
        /** @var array{data: array{items: list<array<string, mixed>>, pagination: array<string, mixed>}} $payload */
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $payload['data']['items']);
        self::assertSame(3, $payload['data']['pagination']['totalItems']);
        self::assertSame(2, $payload['data']['pagination']['totalPages']);
    }

    public function test_an_inverted_range_is_a_validation_error_not_a_500(): void
    {
        $app = $this->appWithCodes();

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/product-codes/search?crossSectionMm2Min=25&crossSectionMm2Max=4',
        ));

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * @return App<\Psr\Container\ContainerInterface>
     */
    private function appWithCodes(): App
    {
        $app = TestAppFactory::create();
        $repository = $app->getContainer()->get(ProductCodeSearchRepository::class);
        self::assertInstanceOf(InMemoryProductCodeSearchRepository::class, $repository);

        $repository->add(
            $this->code('BHA-PATN/4C4RE', coreCount: 4, crossSectionMm2: 4.0),
            ['CABLE-TYPE' => 'MARINE-OFFSHORE', 'IS-LSZH' => true],
        );
        $repository->add(
            $this->code('BHA-PATN/7C4RE', coreCount: 7, crossSectionMm2: 4.0),
            ['CABLE-TYPE' => 'MARINE-OFFSHORE', 'IS-LSZH' => true],
        );
        // The shipboard range has no construction text, so its facets are unknown.
        $repository->add(
            $this->code('BVA-CAXD/14C4BK', styleCode: 'BV', coreCount: 14, crossSectionMm2: 4.0, stockStatus: 'NOT FOUND'),
        );

        return $app;
    }

    private function code(
        string $codeValue,
        string $styleCode = 'BH',
        int $coreCount = 4,
        float $crossSectionMm2 = 4.0,
        string $stockStatus = 'MAKE',
    ): ProductCodeSearchResult {
        return new ProductCodeSearchResult(
            codeValue: $codeValue,
            styleCode: $styleCode,
            styleName: 'Shipboard Power & Control',
            styleSuffix: 'A',
            jacketTypeCode: null,
            coreColourConfig: 'PA',
            braidArmourScreen: 'T',
            conductorCode: 'N',
            coreCount: $coreCount,
            coreTypeCode: 'C',
            crossSectionRaw: '4',
            crossSectionUnit: 'mm2',
            crossSectionMm2: $crossSectionMm2,
            crossSectionNominalMm2: null,
            setCores: 1,
            jacketColour: 'RE',
            stockStatus: $stockStatus,
            isCurrent: true,
            weightKgPerKm: 318.5,
            description: 'Shipboard power and control',
        );
    }
}
