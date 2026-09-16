<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Content\PublicContentRepository;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;

final class PublicContentEndpointsTest extends TestCase
{
    public function test_public_about_endpoint_is_unauthenticated_and_structured(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/public/about'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['hero'] ?? null);
        self::assertSame('ABOUT TRICAB', $data['hero']['eyebrow']);
        self::assertSame('Powering progress with flexible cable solutions', $data['hero']['title']);
        self::assertIsArray(Payload::obj($data['whyTriCab'])['points'] ?? null);
        self::assertContains(
            'Australian and American made cables, built to the highest standards',
            Payload::obj($data['whyTriCab'])['points'],
        );
        self::assertIsArray(Payload::obj($data['history'])['eras'] ?? null);
        self::assertGreaterThanOrEqual(10, count(Payload::list(Payload::obj($data['history'])['eras'])));
        self::assertSame('https://www.youtube.com/watch?v=MDwK6J5dD4s', Payload::obj($data['documentary'])['externalUrl']);
        self::assertIsArray($data['media'] ?? null);
        self::assertSame('/media/about/melbourne-facility.png', Payload::obj(Payload::obj($data['media'])['melbourneFacility'])['url']);
        self::assertTrue(Payload::obj(Payload::obj($data['media'])['melbourneFacility'])['available']);
        self::assertStringContainsString('Port Melbourne', Payload::str(Payload::obj(Payload::obj($data['media'])['melbourneFacility'])['alt']));
    }

    public function test_public_contact_endpoint_preserves_web_sections_and_optional_fields(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/public/contact'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['sections'] ?? null);
        self::assertSame('head-office', Payload::obj(Payload::list($data['sections'])[0])['id']);
        self::assertSame('Corporate Head Office', Payload::obj(Payload::list($data['sections'])[0])['title']);

        $headOffice = Payload::obj(Payload::list(Payload::obj(Payload::list($data['sections'])[0])['offices'])[0]);
        self::assertSame('Melbourne, Australia', $headOffice['location']);
        self::assertSame('+61 3 9081 7300', $headOffice['phone']);
        self::assertSame('melbourne@tricab.com', $headOffice['email']);
        self::assertSame('33 Prohasky Street, Port Melbourne 3207', $headOffice['address']);

        $manufacturingMelbourne = Payload::obj(Payload::list(Payload::obj(Payload::list($data['sections'])[1])['offices'])[0]);
        self::assertSame('Melbourne, Australia', $manufacturingMelbourne['location']);
        self::assertSame('melbourne@tricab.com', $manufacturingMelbourne['email']);
        self::assertArrayNotHasKey('phone', $manufacturingMelbourne);
        self::assertArrayNotHasKey('address', $manufacturingMelbourne);

        $sales = null;
        foreach (Payload::objList($data['sections']) as $section) {
            if (($section['id'] ?? null) === 'sales') {
                $sales = Payload::obj($section);
                break;
            }
        }
        self::assertNotNull($sales);
        $salesSection = Payload::obj($sales);
        self::assertIsArray($salesSection['subsections']);
        self::assertSame('Australia', Payload::obj(Payload::list($salesSection['subsections'])[0])['title']);
        self::assertCount(6, Payload::list(Payload::obj(Payload::list($salesSection['subsections'])[0])['offices']));
    }

    public function test_content_repository_loads_extracted_files(): void
    {
        $repo = PublicContentRepository::fromProjectRoot(dirname(__DIR__, 2));
        $about = $repo->about();
        $contact = $repo->contact();

        self::assertSame('Inside TriCab', Payload::obj($about['documentary'])['title']);
        self::assertTrue(Payload::obj(Payload::obj($about['media'])['wire'])['available']);
        self::assertSame(
            'Global offices and regional sales teams ready to support your project.',
            $contact['intro'],
        );
        self::assertCount(5, $contact['sections']);
    }
}
