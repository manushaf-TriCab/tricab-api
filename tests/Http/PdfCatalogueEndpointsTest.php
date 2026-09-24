<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\PdfCatalogue\Persistence\InMemoryPdfCatalogueRepository;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository;
use TriCab\Core\PdfCatalogue\Module;

final class PdfCatalogueEndpointsTest extends TestCase
{
    public function test_unauthenticated_list_is_rejected(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/catalogues'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', Payload::obj($payload['error'])['code']);
    }

    public function test_authenticated_without_permission_is_forbidden(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/catalogues'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', Payload::obj($payload['error'])['code']);
    }

    public function test_list_returns_active_inventory_with_pur_marine_regions(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ]);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/catalogues'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        $data = Payload::obj($payload['data']);
        self::assertSame(10, $data['count']);
        $items = Payload::objList($data['items']);
        self::assertCount(10, $items);

        $titles = array_map(static fn (array $row): string => Payload::str($row['title'] ?? ''), $items);
        self::assertSame(
            [
                'ALIFLEX Marine',
                'DALI - Lighting Automation',
                'Drag Chain, Festoon & Elevator',
                'Food & Beverage',
                'PUR Marine',
                'Ship Loaders & Cranes',
                'Shore Power',
                'Temporary Power',
                'Water Treatment',
                'Marine LSZH',
            ],
            $titles,
        );

        $pur = null;
        foreach ($items as $item) {
            if (Payload::str($item['title'] ?? '') === 'PUR Marine') {
                $pur = $item;
                break;
            }
        }
        self::assertNotNull($pur);
        self::assertSame('12.0', $pur['issue']);
        self::assertSame(['AU_NZ', 'ASIA'], $pur['regions']);
        self::assertFalse($pur['available']);
        self::assertArrayNotHasKey('documentBlobPath', $pur);
        self::assertArrayNotHasKey('url', $pur);

        $purMatches = array_values(array_filter(
            $items,
            static fn (array $row): bool => Payload::str($row['title'] ?? '') === 'PUR Marine',
        ));
        self::assertCount(1, $purMatches);
    }

    public function test_inactive_catalogues_are_excluded_from_list_and_detail(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ]);

        $inactiveId = InMemoryPdfCatalogueRepository::deterministicPublicId('aliflex-marine');
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $container->set(
            PdfCatalogueRepository::class,
            new InMemoryPdfCatalogueRepository([], ['aliflex-marine']),
        );

        $list = TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/catalogues')));
        $titles = array_map(
            static fn (array $row): string => Payload::str($row['title'] ?? ''),
            Payload::objList(Payload::obj($list['data'])['items']),
        );
        self::assertNotContains('ALIFLEX Marine', $titles);
        self::assertSame(9, Payload::obj($list['data'])['count']);

        $detail = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/catalogues/' . rawurlencode($inactiveId),
        ));
        self::assertSame(404, $detail->getStatusCode());
        self::assertSame(
            'CATALOGUE_NOT_FOUND',
            Payload::obj(TestAppFactory::json($detail)['error'])['code'],
        );
    }

    public function test_detail_and_missing_catalogue(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ]);

        $publicId = InMemoryPdfCatalogueRepository::deterministicPublicId('marine-lszh');
        $ok = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/catalogues/' . rawurlencode($publicId),
        ));
        $payload = TestAppFactory::json($ok);
        self::assertSame(200, $ok->getStatusCode());
        $item = Payload::obj($payload['data']);
        self::assertSame('Marine LSZH', $item['title']);
        self::assertSame(['USA'], $item['regions']);

        $missing = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/catalogues/00000000-0000-4000-a000-000000000099',
        ));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(
            'CATALOGUE_NOT_FOUND',
            Payload::obj(TestAppFactory::json($missing)['error'])['code'],
        );
    }

    public function test_file_unavailable_when_document_not_migrated(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ]);

        $publicId = InMemoryPdfCatalogueRepository::deterministicPublicId('pur-marine');
        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/catalogues/' . rawurlencode($publicId) . '/file',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('CATALOGUE_DOCUMENT_UNAVAILABLE', Payload::obj($payload['error'])['code']);
        self::assertSame('That catalogue document is not available yet.', Payload::obj($payload['error'])['message']);
    }

    public function test_file_issues_short_lived_url_when_available(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ]);

        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $container->set(
            PdfCatalogueRepository::class,
            new InMemoryPdfCatalogueRepository(['pur-marine' => true]),
        );

        $publicId = InMemoryPdfCatalogueRepository::deterministicPublicId('pur-marine');
        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/catalogues/' . rawurlencode($publicId) . '/file',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        $data = Payload::obj($payload['data']);
        self::assertStringStartsWith('memory://', Payload::str($data['url'] ?? ''));
        self::assertSame('GET', $data['httpMethod']);
        self::assertSame('PUR Marine.pdf', $data['fileName']);
        self::assertSame('application/pdf', $data['contentType']);
        self::assertSame($publicId, $data['publicId']);
        self::assertArrayNotHasKey('documentBlobPath', $data);
        self::assertArrayNotHasKey('container', $data);
    }

    public function test_file_requires_permission(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN']);

        $publicId = InMemoryPdfCatalogueRepository::deterministicPublicId('pur-marine');
        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/catalogues/' . rawurlencode($publicId) . '/file',
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @param list<string> $permissionCodes
     * @param App<ContainerInterface> $app
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $this->userRepository($app)->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function userRepository(App $app): InMemoryApiUserRepository
    {
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $repo = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repo);

        return $repo;
    }
}
