<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Technical\TechnicalContentRepository;
use TriCab\Api\Tests\TestAppFactory;

final class TechnicalEndpointsTest extends TestCase
{
    public function test_sections_requires_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/sections'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $payload['error']['code']);
    }

    public function test_sections_returns_legacy_faithful_ia(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/sections'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Technical', $data['title']);
        self::assertIsArray($data['sections']);
        self::assertCount(4, $data['sections']);

        $sectionIds = array_map(static fn (array $s): string => (string) $s['id'], $data['sections']);
        self::assertSame(['general', 'industrial', 'sbo', 'accessories'], $sectionIds);

        $allIds = [];
        foreach ($data['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $allIds[] = $item['id'];
            }
        }

        self::assertContains('lugging', $allIds);
        self::assertContains('voltage-drop', $allIds);
        self::assertContains('cable-calculator', $allIds);
        self::assertContains('general-installation', $allIds);
        self::assertNotContains('current-ratings', $allIds);
        self::assertNotContains('mining-cables', $allIds);
        self::assertNotContains('hv-calculator', $allIds);
        self::assertNotContains('solar-cables', $allIds);

        $accessories = $data['sections'][3];
        self::assertSame('staff_or_sw_digit_market', $accessories['visibilityPolicy']);
    }

    public function test_article_lugging_is_available(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/articles/lugging'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Lugging Recommendation', $data['title']);
        self::assertSame('steps', $data['blocks'][0]['type']);
        self::assertCount(5, $data['blocks'][0]['items']);
    }

    public function test_voltage_drop_table_preserves_legacy_fixture(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/tables/voltage-drop'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('mV/A·m', $data['units']);
        self::assertSame(1.155, $data['singlePhaseFactor']);
        self::assertTrue($data['source']['notACalculator']);

        $row50 = null;
        foreach ($data['rows'] as $row) {
            if ((float) $row['csaMm2'] === 50.0) {
                $row50 = $row;
                break;
            }
        }
        self::assertNotNull($row50);
        self::assertSame(0.869, $row50['values']['cu_sct_90']);
    }

    public function test_unknown_article_is_not_found(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/articles/does-not-exist'));
        $payload = TestAppFactory::json($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('TECHNICAL_ARTICLE_NOT_FOUND', $payload['error']['code']);
    }

    public function test_product_coding_industrial_smoke(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/product-coding/industrial'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('industrial', $payload['data']['mode']);
        self::assertCount(9, $payload['data']['segments']);
    }

    public function test_product_coding_invalid_mode_is_not_found(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/product-coding/nope'));
        $payload = TestAppFactory::json($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('TECHNICAL_PRODUCT_CODING_NOT_FOUND', $payload['error']['code']);
    }

    public function test_documents_catalog_smoke(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/documents'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertArrayHasKey('families', $payload['data']);
        self::assertArrayHasKey('installation-guides', $payload['data']['families']);
    }

    public function test_documents_category_query_smoke(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/technical/documents?category=bep-pvc-disposal'),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('bep-pvc-disposal', $payload['data']['category']);
        self::assertCount(1, $payload['data']['items']);
    }

    public function test_repository_excludes_speculative_and_disabled_ids(): void
    {
        $repo = TechnicalContentRepository::fromProjectRoot(dirname(__DIR__, 2));
        $sections = $repo->sections();
        $ids = [];
        foreach ($sections['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $ids[] = $item['id'];
                if ($item['id'] === 'product-coding') {
                    self::assertSame('industrial', $item['codingMode'] ?? null);
                }
                if ($item['id'] === 'general-installation') {
                    self::assertSame('ARTICLE', $item['type']);
                    self::assertSame('industrial-general-installation', $item['contentKey'] ?? null);
                }
            }
        }
        self::assertNotContains('mining-cables', $ids);
        self::assertNotContains('hv-power', $ids);
        self::assertContains('voltage-drop', $ids);
        self::assertSame(31, count($ids));
    }
}
