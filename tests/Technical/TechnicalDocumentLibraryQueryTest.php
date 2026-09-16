<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Technical;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Storage\AzureProductBlobStore;
use TriCab\Api\Technical\Document\InMemoryTechnicalDocumentLibraryRepository;
use TriCab\Api\Technical\Document\ResolveTechnicalDocumentsQuery;
use TriCab\Api\Technical\Document\TechnicalDocumentCategoryCodes;
use TriCab\Api\Technical\Document\TechnicalDocumentClientUrlResolver;
use TriCab\Api\Technical\Document\TechnicalDocumentPathBuilder;
use TriCab\Api\Technical\Document\TechnicalDocumentRecord;
use TriCab\Api\Technical\TechnicalAccessoriesVisibility;
use TriCab\Api\Technical\TechnicalContentRepository;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\Product\Storage\RecordingBlobStore;

final class TechnicalDocumentLibraryQueryTest extends TestCase
{
    public function test_path_builder_does_not_require_product_public_id(): void
    {
        $path = (new TechnicalDocumentPathBuilder())->documentPath(
            '11111111-1111-1111-1111-111111111111',
            'Industrial-Cert.pdf',
        );

        self::assertSame(
            'products/technical/documents/11111111-1111-1111-1111-111111111111/Industrial-Cert.pdf',
            $path,
        );
        self::assertStringNotContainsString('products/products/', $path);
    }

    public function test_query_returns_technical_shared_industrial_and_sbo_from_library(): void
    {
        $library = new InMemoryTechnicalDocumentLibraryRepository();
        $library->seed(new TechnicalDocumentRecord(
            publicId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            title: 'Industrial shared certificate',
            originalFileName: 'industrial-shared.pdf',
            documentTypeCode: 'certificate',
            categoryCodes: [TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS],
            issuer: 'TriCab',
            storagePath: 'products/technical/documents/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/industrial-shared.pdf',
            publicUrl: 'https://example.test/industrial-shared.pdf',
            checksumSha256: str_repeat('a', 64),
            sourceLegacyId: '3725',
        ));
        $library->seed(new TechnicalDocumentRecord(
            publicId: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            title: 'SBO shared certificate',
            originalFileName: 'sbo-shared.pdf',
            documentTypeCode: 'certificate',
            categoryCodes: [TechnicalDocumentCategoryCodes::SBO_CERTS],
            issuer: 'DNV',
            checksumSha256: str_repeat('b', 64),
            sourceLegacyId: '9001',
        ));

        $query = $this->query($library);

        $industrial = $query->execute(TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS);
        self::assertSame('technical-document-library', $industrial['itemSource']);
        self::assertCount(1, Payload::list($industrial['items']));
        self::assertSame('Industrial shared certificate', Payload::obj(Payload::list($industrial['items'])[0])['title']);
        self::assertSame('certificate', Payload::obj(Payload::list($industrial['items'])[0])['documentTypeCode']);

        $sbo = $query->execute(TechnicalDocumentCategoryCodes::SBO_CERTS);
        self::assertSame('technical-document-library', $sbo['itemSource']);
        self::assertSame('SBO shared certificate', Payload::obj(Payload::list($sbo['items'])[0])['title']);
    }

    public function test_library_document_url_is_signed_read_only_and_not_persisted_public_url(): void
    {
        $canonical = 'https://tricabv2.blob.core.windows.net/products/products/technical/documents/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/TriCab%20Warranty.pdf';
        $path = 'products/technical/documents/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/TriCab Warranty.pdf';
        $library = new InMemoryTechnicalDocumentLibraryRepository();
        $library->seed(new TechnicalDocumentRecord(
            publicId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            title: 'Warranty with spaces',
            originalFileName: 'TriCab Warranty.pdf',
            documentTypeCode: 'certificate',
            categoryCodes: [TechnicalDocumentCategoryCodes::WARRANTY],
            storageContainer: 'products',
            storagePath: $path,
            publicUrl: $canonical,
        ));

        $query = $this->query($library, $this->azureClientUrls());
        $item = Payload::obj(Payload::list($query->execute(TechnicalDocumentCategoryCodes::WARRANTY)['items'])[0]);

        self::assertArrayHasKey('url', $item);
        self::assertIsString($item['url']);
        self::assertStringStartsWith(
            'https://tricabv2.blob.core.windows.net/products/products/technical/documents/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/',
            (string) $item['url'],
        );
        self::assertStringContainsString('TriCab%20Warranty.pdf', (string) $item['url']);
        self::assertStringContainsString('?', (string) $item['url']);
        parse_str((string) parse_url((string) $item['url'], PHP_URL_QUERY), $sas);
        self::assertSame('r', $sas['sp'] ?? null);
        self::assertSame('b', $sas['sr'] ?? null);
        self::assertSame('https', $sas['spr'] ?? null);
        self::assertNotSame('', $sas['se'] ?? '');
        self::assertArrayHasKey('sig', $sas);
        // Persisted PublicUrl must remain unsigned (resolver never writes back).
        self::assertSame($canonical, $library->listActiveByCategory(TechnicalDocumentCategoryCodes::WARRANTY)[0]->publicUrl);
        self::assertStringNotContainsString('sig=', (string) $library->listActiveByCategory(TechnicalDocumentCategoryCodes::WARRANTY)[0]->publicUrl);
    }

    public function test_signing_failure_omits_url_instead_of_unsigned_private_blob(): void
    {
        $library = new InMemoryTechnicalDocumentLibraryRepository();
        $library->seed(new TechnicalDocumentRecord(
            publicId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            title: 'Unsigned trap',
            originalFileName: 'trap.pdf',
            documentTypeCode: 'certificate',
            categoryCodes: [TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS],
            storageContainer: 'products',
            storagePath: 'products/technical/documents/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/trap.pdf',
            publicUrl: 'https://tricabv2.blob.core.windows.net/products/products/technical/documents/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/trap.pdf',
        ));

        $broken = new RecordingBlobStore();
        $broken->failNextIssueReadDownload = true;
        $settings = $this->azureSettings();
        $resolver = new TechnicalDocumentClientUrlResolver($settings, $broken);
        $query = $this->query($library, $resolver);
        $item = Payload::obj(Payload::list($query->execute(TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS)['items'])[0]);

        self::assertArrayNotHasKey('url', $item);
    }

    public function test_query_returns_product_associated_and_multi_product_shared(): void
    {
        $library = new InMemoryTechnicalDocumentLibraryRepository();
        $library->seed(new TechnicalDocumentRecord(
            publicId: 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            title: 'Product-linked certificate',
            originalFileName: 'product-linked.pdf',
            documentTypeCode: 'certificate',
            categoryCodes: [TechnicalDocumentCategoryCodes::SBO_CERTS],
            productPublicIds: ['product-public-1'],
            stylePublicIds: ['style-public-dva'],
            sourceLegacyId: '4347',
        ));
        $library->seed(new TechnicalDocumentRecord(
            publicId: 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            title: 'Multi-product shared certificate',
            originalFileName: 'multi-product.pdf',
            documentTypeCode: 'certificate',
            categoryCodes: [TechnicalDocumentCategoryCodes::SBO_CERTS],
            productPublicIds: ['product-public-1', 'product-public-2'],
            stylePublicIds: ['style-bha', 'style-hta'],
            sourceLegacyId: '4338',
        ));

        $items = Payload::list($this->query($library)->execute(TechnicalDocumentCategoryCodes::SBO_CERTS)['items']);
        self::assertCount(2, $items);
        self::assertSame(['product-public-1'], Payload::obj($items[0])['productPublicIds']);
        self::assertSame(['product-public-1', 'product-public-2'], Payload::obj($items[1])['productPublicIds']);
    }

    public function test_query_returns_accessory_warranty_and_installation_guide(): void
    {
        $library = new InMemoryTechnicalDocumentLibraryRepository();
        $library->seed(new TechnicalDocumentRecord(
            publicId: 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            title: 'Accessory warranty',
            originalFileName: 'acc-warranty.pdf',
            documentTypeCode: 'certificate',
            categoryCodes: [TechnicalDocumentCategoryCodes::ACCESSORY_WARRANTY],
            sourceLegacyId: '7001',
        ));
        $library->seed(new TechnicalDocumentRecord(
            publicId: 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            title: 'Installation guide X07',
            originalFileName: 'x07-install.pdf',
            documentTypeCode: 'installation-guide',
            categoryCodes: [TechnicalDocumentCategoryCodes::INSTALLATION_GUIDES],
            stylePublicIds: ['style-x07'],
            sourceLegacyId: '8001',
        ));

        $query = $this->query($library);
        $warranty = $query->execute(TechnicalDocumentCategoryCodes::ACCESSORY_WARRANTY);
        self::assertSame('Accessory warranty', Payload::obj(Payload::list($warranty['items'])[0])['title']);
        $guides = $query->execute(TechnicalDocumentCategoryCodes::INSTALLATION_GUIDES);
        self::assertSame('installation-guide', Payload::obj(Payload::list($guides['items'])[0])['documentTypeCode']);
    }

    public function test_empty_library_preserves_catalog_json_items(): void
    {
        $query = $this->query(new InMemoryTechnicalDocumentLibraryRepository());
        $docs = $query->execute('bep-pvc-disposal');

        self::assertSame('catalog-json', $docs['itemSource']);
        self::assertCount(1, Payload::list($docs['items']));
        self::assertSame('BLOCKED', Payload::obj(Payload::list($docs['items'])[0])['migrationStatus']);
        self::assertArrayNotHasKey('url', Payload::obj(Payload::list($docs['items'])[0]));
    }

    public function test_accessory_category_codes_remain_server_gated(): void
    {
        self::assertTrue(TechnicalAccessoriesVisibility::isAccessoryDocumentCategory(
            TechnicalDocumentCategoryCodes::ACCESSORY_WARRANTY,
        ));
        self::assertTrue(TechnicalAccessoriesVisibility::isAccessoryDocumentCategory(
            TechnicalDocumentCategoryCodes::INSTALLATION_GUIDES,
        ));
        self::assertFalse(TechnicalAccessoriesVisibility::isAccessoryDocumentCategory(
            TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS,
        ));
    }

    private function query(
        InMemoryTechnicalDocumentLibraryRepository $library,
        ?TechnicalDocumentClientUrlResolver $clientUrls = null,
    ): ResolveTechnicalDocumentsQuery {
        return new ResolveTechnicalDocumentsQuery(
            TechnicalContentRepository::fromProjectRoot(dirname(__DIR__, 2)),
            $library,
            $clientUrls ?? new TechnicalDocumentClientUrlResolver(
                Settings::fromEnvironment([
                    'APP_ENV' => 'development',
                    'USER_REPOSITORY' => 'memory',
                    'BLOB_AUTH_MODE' => 'disabled',
                ]),
                new RecordingBlobStore(),
            ),
        );
    }

    private function azureClientUrls(): TechnicalDocumentClientUrlResolver
    {
        return new TechnicalDocumentClientUrlResolver(
            $this->azureSettings(),
            new AzureProductBlobStore($this->azureSettings()),
        );
    }

    private function azureSettings(): Settings
    {
        $key = base64_encode(str_repeat('d', 32));

        return Settings::fromEnvironment([
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
            'BLOB_ACCOUNT_KEY' => $key,
            'BLOB_ENDPOINT' => 'https://tricabv2.blob.core.windows.net',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => '',
            'BLOB_DOWNLOAD_SAS_TTL_SECONDS' => '300',
        ]);
    }
}
