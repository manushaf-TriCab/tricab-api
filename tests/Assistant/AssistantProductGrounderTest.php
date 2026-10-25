<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantProductGrounder;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Catalogue\CatalogueNavigationImageSigner;
use TriCab\Api\Product\Media\StableMediaUrlSigner;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProduct3DModelRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCustomerViewRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPublicQueryRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Api\Tests\Product\Storage\RecordingBlobStore;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\Service\GetAdminProductCustomerViewService;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Contract\ProductMediaRepository;

final class AssistantProductGrounderTest extends TestCase
{
    private const BLOB = 'https://tricabv2.blob.core.windows.net/products/products/abc/images/def/BHP-cross-section.png';

    public function test_private_blob_thumbnail_is_signed_on_the_card(): void
    {
        $card = $this->grounder()->fromSearchResult(
            $this->searchResult(self::BLOB),
            null,
            AssistantCatalogueVisibility::PUBLIC,
        )->toCard();

        $imageUrl = $card['imageUrl'];
        self::assertIsString($imageUrl);
        self::assertStringStartsWith(self::BLOB . '?', $imageUrl);
        self::assertStringContainsString('sp=r', $imageUrl);
        self::assertStringContainsString('sig=', $imageUrl);
    }

    public function test_public_media_path_thumbnail_is_unchanged(): void
    {
        $card = $this->grounder()->fromSearchResult(
            $this->searchResult('/media/products/BHP.png'),
            null,
            AssistantCatalogueVisibility::PUBLIC,
        )->toCard();

        self::assertSame('/media/products/BHP.png', $card['imageUrl']);
    }

    public function test_missing_thumbnail_stays_null(): void
    {
        $card = $this->grounder()->fromSearchResult(
            $this->searchResult(null),
            null,
            AssistantCatalogueVisibility::PUBLIC,
        )->toCard();

        self::assertNull($card['imageUrl']);
    }

    public static function imageSigner(ProductMediaRepository $media): CatalogueNavigationImageSigner
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
            'DEV_AUTH_ENABLED' => 'true',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'BLOB_AUTH_MODE' => 'connection-string',
            'BLOB_ACCOUNT_NAME' => 'tricabv2',
            'BLOB_ACCOUNT_KEY' => base64_encode(str_repeat('c', 32)),
            'BLOB_ENDPOINT' => 'https://tricabv2.blob.core.windows.net',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => '',
            'BLOB_DOWNLOAD_SAS_TTL_SECONDS' => '300',
        ]);

        return new CatalogueNavigationImageSigner(
            new ProductDevMediaUrlResolver($settings, $media, new RecordingBlobStore()),
            $settings,
            new StableMediaUrlSigner($settings),
        );
    }

    private function grounder(): AssistantProductGrounder
    {
        $store = new InMemoryProductStore();
        $threeDModels = new InMemoryProduct3DModelRepository($store);

        return new AssistantProductGrounder(
            new GetPublicProductService(new InMemoryProductPublicQueryRepository($store, $threeDModels)),
            new GetAdminProductCustomerViewService(new InMemoryProductCustomerViewRepository($store, $threeDModels)),
            self::imageSigner($this->createStub(ProductMediaRepository::class)),
        );
    }

    private function searchResult(?string $thumbnail): ProductSearchResult
    {
        return new ProductSearchResult(
            publicId: '01HZX0000000000000000000BH',
            productName: 'BFOP BFCB Shipboard Fire Resistant',
            productCode: 'BHP',
            slug: null,
            categoryName: 'Shipboard',
            seriesName: 'Braided Power & Control',
            statusCode: 'published',
            thumbnailUrl: $thumbnail,
        );
    }
}
