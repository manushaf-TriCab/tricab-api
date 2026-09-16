<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Technical;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Technical\Document\InMemoryTechnicalDocumentLibraryRepository;
use TriCab\Api\Technical\Document\ResolveTechnicalDocumentsQuery;
use TriCab\Api\Technical\Document\TechnicalDocumentCategoryCodes;
use TriCab\Api\Technical\Document\TechnicalDocumentPathBuilder;
use TriCab\Api\Technical\Document\TechnicalDocumentRecord;
use TriCab\Api\Technical\TechnicalAccessoriesVisibility;
use TriCab\Api\Technical\TechnicalContentRepository;

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
        self::assertCount(1, $industrial['items']);
        self::assertSame('Industrial shared certificate', $industrial['items'][0]['title']);
        self::assertSame('certificate', $industrial['items'][0]['documentTypeCode']);

        $sbo = $query->execute(TechnicalDocumentCategoryCodes::SBO_CERTS);
        self::assertSame('technical-document-library', $sbo['itemSource']);
        self::assertSame('SBO shared certificate', $sbo['items'][0]['title']);
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

        $items = $this->query($library)->execute(TechnicalDocumentCategoryCodes::SBO_CERTS)['items'];
        self::assertCount(2, $items);
        self::assertSame(['product-public-1'], $items[0]['productPublicIds']);
        self::assertSame(['product-public-1', 'product-public-2'], $items[1]['productPublicIds']);
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
        self::assertSame('Accessory warranty', $warranty['items'][0]['title']);
        $guides = $query->execute(TechnicalDocumentCategoryCodes::INSTALLATION_GUIDES);
        self::assertSame('installation-guide', $guides['items'][0]['documentTypeCode']);
    }

    public function test_empty_library_preserves_catalog_json_items(): void
    {
        $query = $this->query(new InMemoryTechnicalDocumentLibraryRepository());
        $docs = $query->execute('bep-pvc-disposal');

        self::assertSame('catalog-json', $docs['itemSource']);
        self::assertCount(1, $docs['items']);
        self::assertSame('BLOCKED', $docs['items'][0]['migrationStatus']);
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

    private function query(InMemoryTechnicalDocumentLibraryRepository $library): ResolveTechnicalDocumentsQuery
    {
        return new ResolveTechnicalDocumentsQuery(
            TechnicalContentRepository::fromProjectRoot(dirname(__DIR__, 2)),
            $library,
        );
    }
}
