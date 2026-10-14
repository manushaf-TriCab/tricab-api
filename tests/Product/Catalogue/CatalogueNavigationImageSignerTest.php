<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Catalogue;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Catalogue\CatalogueNavigationImageSigner;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Api\Tests\Product\Storage\RecordingBlobStore;
use TriCab\Core\Product\Contract\ProductMediaRepository;

final class CatalogueNavigationImageSignerTest extends TestCase
{
    private const BLOB = 'https://tricabv2.blob.core.windows.net/products/products/abc/images/def/cross-section.png';

    public function test_private_blob_url_is_signed_and_not_the_stored_value(): void
    {
        $signer = $this->signer(300);
        $signed = $signer->present(self::BLOB);

        self::assertNotNull($signed);
        self::assertNotSame(self::BLOB, $signed);
        self::assertStringStartsWith(self::BLOB . '?', $signed);
        $query = $this->query($signed);
        self::assertSame('r', $query['sp'] ?? null);
        self::assertArrayHasKey('sig', $query);
        self::assertStringNotContainsString('AccountKey', $signed);
    }

    public function test_public_asset_and_media_paths_are_unchanged(): void
    {
        $blob = new RecordingBlobStore();
        $signer = $this->signer(300, $blob);

        self::assertSame('/assets/BV.png', $signer->present('/assets/BV.png'));
        self::assertSame('/media/catalogue/BV.png', $signer->present('/media/catalogue/BV.png'));
        self::assertNull($blob->lastTtl);
    }

    public function test_null_blank_and_invalid_urls_do_not_sign(): void
    {
        $blob = new RecordingBlobStore();
        $signer = $this->signer(300, $blob);

        self::assertNull($signer->present(null));
        self::assertNull($signer->present('   '));
        self::assertNull($signer->present('not a url'));
        self::assertNull($signer->present('javascript:alert(1)'));
        self::assertNull($blob->lastTtl);
    }

    public function test_external_https_url_is_not_signed(): void
    {
        $blob = new RecordingBlobStore();
        $signer = $this->signer(300, $blob);
        $external = 'https://cdn.example.com/catalogue/bv.png';

        self::assertSame($external, $signer->present($external));
        self::assertNull($blob->lastTtl);
    }

    public function test_already_signed_blob_url_receives_a_fresh_signature(): void
    {
        $signer = $this->signer(300);
        $expired = self::BLOB . '?sp=r&se=2000-01-01T00:00:00Z&sig=expired';
        $signed = $signer->present($expired);

        self::assertNotNull($signed);
        self::assertNotSame($expired, $signed);
        self::assertStringStartsWith(self::BLOB . '?', $signed);
        self::assertSame('r', $this->query($signed)['sp'] ?? null);
        self::assertArrayNotHasKey('expired', $this->query($signed));
    }

    public function test_canonical_stored_url_strips_a_signature_and_keeps_public_paths(): void
    {
        $signer = $this->signer(300);
        $signed = self::BLOB . '?sp=r&sig=test';

        self::assertSame(self::BLOB, $signer->canonicalStoredUrl($signed));
        self::assertSame('/assets/BV.png', $signer->canonicalStoredUrl('/assets/BV.png'));
        self::assertNull($signer->canonicalStoredUrl(null));
    }

    public function test_cache_control_ends_before_the_read_signature(): void
    {
        self::assertSame(
            'public, max-age=60, stale-while-revalidate=120',
            $this->signer(300)->cacheControl(true),
        );
        self::assertSame(
            'private, max-age=10, stale-while-revalidate=20',
            $this->signer(60)->cacheControl(false),
        );
    }

    public function test_public_node_signs_the_node_children_and_product_card_only(): void
    {
        $signer = $this->signer(300);
        $signed = $signer->signPublicNode([
            'nodeName' => 'IEC 60092-350',
            'imageUrl' => self::BLOB,
            'children' => [
                ['nodeName' => 'UP', 'imageUrl' => '/assets/UP.png', 'children' => []],
            ],
            'products' => [
                ['name' => 'BVP', 'thumbnailUrl' => self::BLOB],
            ],
        ]);

        self::assertIsString($signed['imageUrl']);
        self::assertStringContainsString('sp=r', $signed['imageUrl']);
        $children = $signed['children'];
        $products = $signed['products'];
        self::assertIsArray($children);
        self::assertIsArray($products);
        $child = $children[0] ?? null;
        $product = $products[0] ?? null;
        self::assertIsArray($child);
        self::assertIsArray($product);
        self::assertSame('/assets/UP.png', $child['imageUrl']);
        self::assertIsString($product['thumbnailUrl']);
        self::assertStringContainsString('sp=r', $product['thumbnailUrl']);
        self::assertSame('IEC 60092-350', $signed['nodeName']);
    }

    private function signer(int $ttl, ?RecordingBlobStore $blob = null): CatalogueNavigationImageSigner
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
            'BLOB_DOWNLOAD_SAS_TTL_SECONDS' => (string) $ttl,
        ]);

        return new CatalogueNavigationImageSigner(
            new ProductDevMediaUrlResolver(
                $settings,
                $this->createStub(ProductMediaRepository::class),
                $blob ?? new RecordingBlobStore(),
            ),
            $settings,
        );
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parsed);
        $query = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $query[$key] = $value;
            }
        }

        return $query;
    }
}
