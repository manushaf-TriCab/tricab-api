<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence\InMemory;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductThumbnailPresentationRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class ProductThumbnailPresentationRepositoryTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    public function test_admin_can_set_and_clear_thumbnail_background_color(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => 'Thumbnail BG Widget',
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $publicId = (string) TestAppFactory::json($create)['data']['publicId'];

        $update = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/admin/products/' . $publicId . '/thumbnail-presentation',
            ['thumbnailBackgroundColor' => '#f8eee6'],
        ));
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());

        $repo = new InMemoryProductThumbnailPresentationRepository($this->productStore($app));
        self::assertSame(
            '#F8EEE6',
            $repo->findThumbnailBackgroundColor(ProductPublicId::fromString($publicId)),
        );

        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $publicId));
        self::assertSame(200, $detail->getStatusCode(), (string) $detail->getBody());
        self::assertSame('#F8EEE6', TestAppFactory::json($detail)['data']['thumbnailBackgroundColor']);

        $clear = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/admin/products/' . $publicId . '/thumbnail-presentation',
            ['thumbnailBackgroundColor' => null],
        ));
        self::assertSame(200, $clear->getStatusCode(), (string) $clear->getBody());
        self::assertNull($repo->findThumbnailBackgroundColor(ProductPublicId::fromString($publicId)));
    }

    public function test_rejects_invalid_hex(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => 'Invalid BG Widget',
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        $publicId = (string) TestAppFactory::json($create)['data']['publicId'];

        $update = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/admin/products/' . $publicId . '/thumbnail-presentation',
            ['thumbnailBackgroundColor' => 'red'],
        ));
        self::assertSame(422, $update->getStatusCode(), (string) $update->getBody());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedReferenceData(App $app): void
    {
        $this->productStore($app)->addFamily([
            'publicId' => self::FAMILY_PUBLIC_ID,
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function productStore(App $app): InMemoryProductStore
    {
        /** @var InMemoryProductStore $store */
        $store = $app->getContainer()->get(InMemoryProductStore::class);

        return $store;
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
