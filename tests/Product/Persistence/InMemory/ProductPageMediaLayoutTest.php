<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence\InMemory;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Product\Domain\ValueObject\DescriptionImagePresentation;

final class ProductPageMediaLayoutTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_PUBLIC_ID = '22222222-2222-2222-2222-222222222222';

    public function test_missing_layout_uses_the_historical_description_image(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Unstyled datasheet');

        $view = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $publicId . '/customer-view',
        ));
        self::assertSame(200, $view->getStatusCode(), (string) $view->getBody());
        $pageMedia = $this->pageMedia($view);
        self::assertSame(DescriptionImagePresentation::WIDTH_SMALL, $pageMedia['descriptionImageWidth']);
        self::assertSame(DescriptionImagePresentation::ALIGN_CENTRE, $pageMedia['descriptionImageAlignment']);
    }

    public function test_save_reload_keeps_description_image_layout_on_the_preview(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Wide diagram');

        $update = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/admin/products/' . $publicId . '/page-media-layout',
            [
                'descriptionImageWidth' => 'full',
                'descriptionImageAlignment' => 'left',
            ],
        ));
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());

        $view = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $publicId . '/customer-view',
        ));
        self::assertSame(200, $view->getStatusCode(), (string) $view->getBody());
        $pageMedia = $this->pageMedia($view);
        self::assertSame('full', $pageMedia['descriptionImageWidth']);
        self::assertSame('left', $pageMedia['descriptionImageAlignment']);
    }

    public function test_rejects_an_unknown_width(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Bad width');

        $update = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/admin/products/' . $publicId . '/page-media-layout',
            [
                'descriptionImageWidth' => 'huge',
                'descriptionImageAlignment' => 'centre',
            ],
        ));
        self::assertSame(422, $update->getStatusCode(), (string) $update->getBody());
    }

    public function test_published_page_and_preview_share_the_saved_layout(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $created = $this->createProductRecord($app, 'Published diagram');

        $update = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/admin/products/' . $created['publicId'] . '/page-media-layout',
            [
                'descriptionImageWidth' => 'medium',
                'descriptionImageAlignment' => 'left',
            ],
        ));
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());

        $markets = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/admin/products/' . $created['publicId'] . '/markets',
            [
                'regionPublicIds' => [self::REGION_PUBLIC_ID],
                'marketPublicIds' => [],
            ],
        ));
        self::assertSame(200, $markets->getStatusCode(), (string) $markets->getBody());

        $publish = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $created['publicId'] . '/publish',
            ['expectedRowVersion' => $created['rowVersion']],
        ));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());

        $public = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/products/' . $created['publicId'],
        ));
        $preview = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $created['publicId'] . '/customer-view',
        ));
        self::assertSame(
            $this->pageMedia($preview),
            $this->pageMedia($public),
        );
        self::assertSame('medium', $this->pageMedia($public)['descriptionImageWidth']);
        self::assertSame('left', $this->pageMedia($public)['descriptionImageAlignment']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedReferenceData(App $app): void
    {
        $store = $app->getContainer()->get(\TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore::class);
        self::assertInstanceOf(\TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore::class, $store);
        $store->addFamily([
            'publicId' => self::FAMILY_PUBLIC_ID,
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
        $store->addRegion([
            'publicId' => self::REGION_PUBLIC_ID,
            'code' => 'ANZ',
            'name' => 'Australia/NZ',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createProduct(App $app, string $name): string
    {
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $name,
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $data = TestAppFactory::json($create)['data'] ?? null;
        self::assertIsArray($data);
        $publicId = $data['publicId'] ?? null;
        self::assertIsString($publicId);

        return $publicId;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{publicId: string, rowVersion: string}
     */
    private function createProductRecord(App $app, string $name): array
    {
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $name,
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $data = TestAppFactory::json($create)['data'] ?? null;
        self::assertIsArray($data);
        $publicId = $data['publicId'] ?? null;
        $rowVersion = $data['rowVersion'] ?? null;
        self::assertIsString($publicId);
        self::assertIsString($rowVersion);

        return ['publicId' => $publicId, 'rowVersion' => $rowVersion];
    }

    /**
     * @return array{descriptionImageWidth: string, descriptionImageAlignment: string}
     */
    private function pageMedia(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = TestAppFactory::json($response)['data'] ?? null;
        self::assertIsArray($data);
        $pageMedia = $data['pageMedia'] ?? null;
        self::assertIsArray($pageMedia);
        $width = $pageMedia['descriptionImageWidth'] ?? null;
        $alignment = $pageMedia['descriptionImageAlignment'] ?? null;
        self::assertIsString($width);
        self::assertIsString($alignment);

        return [
            'descriptionImageWidth' => $width,
            'descriptionImageAlignment' => $alignment,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        if ($body !== []) {
            $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        }

        return $request;
    }
}
