<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Technical;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Technical\Document\ResolveTechnicalDocumentsQuery;
use TriCab\Api\Technical\Document\TechnicalDocumentClientUrlResolver;
use TriCab\Api\Technical\Document\TechnicalDocumentCategoryCodes;
use TriCab\Api\Technical\Persistence\AzureSqlTechnicalDocumentLibraryRepository;
use TriCab\Api\Technical\TechnicalContentRepository;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;

/**
 * Phase 4G/4H — Azure SQL Technical library repository.
 * Uses transaction rollback fixtures; does not leave fixture rows behind.
 */
final class AzureSqlTechnicalDocumentLibraryRepositoryTest extends TestCase
{
    private ?SqlConnection $connection = null;

    protected function setUp(): void
    {
        $settings = Environment::settings(dirname(__DIR__, 2));
        if (!$settings->usesAzureSqlUserRepository() || $settings->appEnv === 'production') {
            self::markTestSkipped('Azure SQL non-production repository required.');
        }
        $connection = ContainerFactory::create($settings)->get(SqlConnection::class);
        self::assertInstanceOf(SqlConnection::class, $connection);
        $this->connection = $connection;
    }

    public function test_list_by_category_returns_shared_style_product_and_multi_links_in_order(): void
    {
        $db = $this->requireConnection();
        $db->beginTransaction();
        try {
            $typeId = $this->documentTypeId($db, 'certificate');
            $guideTypeId = $this->documentTypeId($db, 'installation-guide');
            $industrialId = $this->categoryId($db, TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS);
            $sboId = $this->categoryId($db, TechnicalDocumentCategoryCodes::SBO_CERTS);
            $warrantyId = $this->categoryId($db, TechnicalDocumentCategoryCodes::ACCESSORY_WARRANTY);
            $guideCatId = $this->categoryId($db, TechnicalDocumentCategoryCodes::INSTALLATION_GUIDES);

            $productA = $this->anyProduct($db);
            $productB = $this->anyOtherProduct($db, (int) $productA['ProductId']);
            $styleA = $this->anyStyle($db);
            $styleB = $this->anyOtherStyle($db, (int) $styleA['ProductStyleId']);

            $shared = $this->insertDocument($db, [
                'typeId' => $typeId,
                'title' => 'AAA Shared Industrial',
                'file' => 'shared-industrial.pdf',
                'displayOrder' => 1,
                'checksum' => str_repeat('1', 64),
                'legacyId' => 'legacy-shared-1',
            ]);
            $this->linkCategory($db, $shared, $industrialId);

            $styleLinked = $this->insertDocument($db, [
                'typeId' => $typeId,
                'title' => 'BBB Style Linked',
                'file' => 'style-linked.pdf',
                'displayOrder' => 2,
                'checksum' => str_repeat('2', 64),
                'legacyId' => 'legacy-style-1',
            ]);
            $this->linkCategory($db, $styleLinked, $sboId);
            $this->linkStyle($db, $styleLinked, (int) $styleA['ProductStyleId']);

            $productLinked = $this->insertDocument($db, [
                'typeId' => $typeId,
                'title' => 'CCC Product Linked',
                'file' => 'product-linked.pdf',
                'displayOrder' => 3,
                'checksum' => str_repeat('3', 64),
                'legacyId' => 'legacy-product-1',
            ]);
            $this->linkCategory($db, $productLinked, $sboId);
            $this->linkProduct($db, $productLinked, (int) $productA['ProductId']);

            $multi = $this->insertDocument($db, [
                'typeId' => $typeId,
                'title' => 'DDD Multi Link',
                'file' => 'multi-link.pdf',
                'displayOrder' => 4,
                'checksum' => str_repeat('4', 64),
                'legacyId' => 'legacy-multi-1',
            ]);
            $this->linkCategory($db, $multi, $sboId);
            $this->linkCategory($db, $multi, $industrialId);
            $this->linkProduct($db, $multi, (int) $productA['ProductId']);
            $this->linkProduct($db, $multi, (int) $productB['ProductId']);
            $this->linkStyle($db, $multi, (int) $styleA['ProductStyleId']);
            $this->linkStyle($db, $multi, (int) $styleB['ProductStyleId']);

            $inactive = $this->insertDocument($db, [
                'typeId' => $typeId,
                'title' => 'EEE Inactive',
                'file' => 'inactive.pdf',
                'displayOrder' => 5,
                'checksum' => str_repeat('5', 64),
                'legacyId' => 'legacy-inactive-1',
                'isActive' => 0,
            ]);
            $this->linkCategory($db, $inactive, $sboId);

            $deleted = $this->insertDocument($db, [
                'typeId' => $typeId,
                'title' => 'FFF Soft Deleted',
                'file' => 'deleted.pdf',
                'displayOrder' => 6,
                'checksum' => str_repeat('6', 64),
                'legacyId' => 'legacy-deleted-1',
            ]);
            $this->linkCategory($db, $deleted, $sboId);
            $db->execute(
                'UPDATE [technical].[Documents] SET [DeletedAtUtc] = SYSUTCDATETIME() WHERE [DocumentId] = :id',
                [':id' => $deleted],
            );

            $warranty = $this->insertDocument($db, [
                'typeId' => $typeId,
                'title' => 'Accessory warranty fixture',
                'file' => 'acc-warranty.pdf',
                'displayOrder' => 1,
                'checksum' => str_repeat('7', 64),
                'legacyId' => 'legacy-warranty-1',
            ]);
            $this->linkCategory($db, $warranty, $warrantyId);

            $guide = $this->insertDocument($db, [
                'typeId' => $guideTypeId,
                'title' => 'Installation guide fixture',
                'file' => 'install.pdf',
                'displayOrder' => 1,
                'checksum' => str_repeat('8', 64),
                'legacyId' => 'legacy-guide-1',
            ]);
            $this->linkCategory($db, $guide, $guideCatId);

            $repo = new AzureSqlTechnicalDocumentLibraryRepository($db);

            $sharedDoc = $repo->findActiveByLegacyId('legacy-wp', 'legacy-shared-1');
            self::assertNotNull($sharedDoc);
            self::assertSame('AAA Shared Industrial', $sharedDoc->title);
            self::assertContains(TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS, $sharedDoc->categoryCodes);

            $styleDoc = $repo->findActiveByLegacyId('legacy-wp', 'legacy-style-1');
            self::assertNotNull($styleDoc);
            self::assertSame([(string) $styleA['PublicId']], $styleDoc->stylePublicIds);

            $productDoc = $repo->findActiveByLegacyId('legacy-wp', 'legacy-product-1');
            self::assertNotNull($productDoc);
            self::assertSame([(string) $productA['PublicId']], $productDoc->productPublicIds);

            $multiDoc = $repo->findActiveByLegacyId('legacy-wp', 'legacy-multi-1');
            self::assertNotNull($multiDoc);
            self::assertCount(2, $multiDoc->productPublicIds);
            self::assertCount(2, $multiDoc->stylePublicIds);
            self::assertContains(TechnicalDocumentCategoryCodes::SBO_CERTS, $multiDoc->categoryCodes);
            self::assertContains(TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS, $multiDoc->categoryCodes);

            self::assertNull($repo->findActiveByChecksum(str_repeat('5', 64)));
            self::assertNull($repo->findActiveByLegacyId('legacy-wp', 'legacy-deleted-1'));
            self::assertNotNull($repo->findActiveByChecksum(str_repeat('1', 64)));

            $query = new ResolveTechnicalDocumentsQuery(
                TechnicalContentRepository::fromProjectRoot(dirname(__DIR__, 2)),
                $repo,
                ContainerFactory::create(Environment::settings(dirname(__DIR__, 2)))
                    ->get(TechnicalDocumentClientUrlResolver::class),
            );
            $payload = $query->execute(TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS);
            self::assertSame('technical-document-library', $payload['itemSource']);
            $titles = array_map(static fn (array $item): string => Payload::str($item['title'] ?? ''), Payload::objList($payload['items']));
            self::assertContains('AAA Shared Industrial', $titles);
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }

    public function test_unpopulated_category_preserves_catalog_fallback_via_azure_repo(): void
    {
        $db = $this->requireConnection();
        $repo = new AzureSqlTechnicalDocumentLibraryRepository($db);
        // accessory-certs and BEP families remain outside the Phase 4H safe subset.
        self::assertSame([], $repo->listActiveByCategory(TechnicalDocumentCategoryCodes::ACCESSORY_CERTS));

        $query = new ResolveTechnicalDocumentsQuery(
            TechnicalContentRepository::fromProjectRoot(dirname(__DIR__, 2)),
            $repo,
            ContainerFactory::create(Environment::settings(dirname(__DIR__, 2)))
                ->get(TechnicalDocumentClientUrlResolver::class),
        );
        $docs = $query->execute('bep-pvc-disposal');
        self::assertSame('catalog-json', $docs['itemSource']);
        self::assertCount(1, Payload::list($docs['items']));
        self::assertSame('BLOCKED', Payload::obj(Payload::list($docs['items'])[0])['migrationStatus']);
    }

    public function test_http_documents_endpoint_uses_catalog_fallback_when_library_empty(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/documents?category=bep-pvc-disposal'));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('catalog-json', Payload::obj($payload['data'])['itemSource']);
        self::assertCount(1, Payload::list(Payload::obj($payload['data'])['items']));
    }

    public function test_fixture_rollback_does_not_leave_orphan_rows(): void
    {
        $db = $this->requireConnection();
        $before = Payload::int(Payload::obj($db->fetchAll('SELECT COUNT(*) AS c FROM [technical].[Documents]')[0])['c']);
        self::assertGreaterThanOrEqual(204, $before);

        $db->beginTransaction();
        try {
            $typeId = $this->documentTypeId($db, 'certificate');
            $catId = $this->categoryId($db, TechnicalDocumentCategoryCodes::INDUSTRIAL_CERTS);
            $docId = $this->insertDocument($db, [
                'typeId' => $typeId,
                'title' => 'Fixture orphan probe',
                'file' => 'fixture-orphan.pdf',
                'displayOrder' => 99,
                'checksum' => str_repeat('9', 64),
                'legacyId' => 'legacy-orphan-probe',
            ]);
            $this->linkCategory($db, $docId, $catId);
            $mid = Payload::int(Payload::obj($db->fetchAll('SELECT COUNT(*) AS c FROM [technical].[Documents]')[0])['c']);
            self::assertSame($before + 1, $mid);
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }

        $after = Payload::int(Payload::obj($db->fetchAll('SELECT COUNT(*) AS c FROM [technical].[Documents]')[0])['c']);
        self::assertSame($before, $after);
        self::assertNull(
            (new AzureSqlTechnicalDocumentLibraryRepository($db))
                ->findActiveByLegacyId('legacy-wp', 'legacy-orphan-probe'),
        );
    }

    private function requireConnection(): SqlConnection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    private function documentTypeId(SqlConnection $db, string $code): int
    {
        $rows = $db->fetchAll(
            'SELECT [DocumentTypeId] FROM [product].[DocumentTypes] WHERE [DocumentTypeCode] = :c AND [DeletedAtUtc] IS NULL',
            [':c' => $code],
        );
        self::assertNotSame([], $rows, 'Missing DocumentTypeCode ' . $code);

        return Payload::int(Payload::obj($rows[0])['DocumentTypeId']);
    }

    private function categoryId(SqlConnection $db, string $code): int
    {
        $rows = $db->fetchAll(
            'SELECT [DocumentCategoryId] FROM [technical].[DocumentCategories] WHERE [CategoryCode] = :c AND [DeletedAtUtc] IS NULL',
            [':c' => $code],
        );
        self::assertNotSame([], $rows, 'Missing category ' . $code);

        return Payload::int(Payload::obj($rows[0])['DocumentCategoryId']);
    }

    /**
     * @return array{ProductId:int|string, PublicId:string}
     */
    private function anyProduct(SqlConnection $db): array
    {
        $rows = $db->fetchAll(
            'SELECT TOP 1 [ProductId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId] FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL ORDER BY [ProductId]',
        );
        self::assertNotSame([], $rows);
        $row = Payload::obj($rows[0]);

        return ['ProductId' => Payload::int($row['ProductId']), 'PublicId' => Payload::str($row['PublicId'])];
    }

    /**
     * @return array{ProductId:int|string, PublicId:string}
     */
    private function anyOtherProduct(SqlConnection $db, int $excludeId): array
    {
        $rows = $db->fetchAll(
            'SELECT TOP 1 [ProductId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId] FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL AND [ProductId] <> :id ORDER BY [ProductId]',
            [':id' => $excludeId],
        );
        self::assertNotSame([], $rows);
        $row = Payload::obj($rows[0]);

        return ['ProductId' => Payload::int($row['ProductId']), 'PublicId' => Payload::str($row['PublicId'])];
    }

    /**
     * @return array{ProductStyleId:int|string, PublicId:string}
     */
    private function anyStyle(SqlConnection $db): array
    {
        $rows = $db->fetchAll(
            'SELECT TOP 1 [ProductStyleId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId] FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL ORDER BY [ProductStyleId]',
        );
        self::assertNotSame([], $rows);
        $row = Payload::obj($rows[0]);

        return ['ProductStyleId' => Payload::int($row['ProductStyleId']), 'PublicId' => Payload::str($row['PublicId'])];
    }

    /**
     * @return array{ProductStyleId:int|string, PublicId:string}
     */
    private function anyOtherStyle(SqlConnection $db, int $excludeId): array
    {
        $rows = $db->fetchAll(
            'SELECT TOP 1 [ProductStyleId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId] FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ProductStyleId] <> :id ORDER BY [ProductStyleId]',
            [':id' => $excludeId],
        );
        self::assertNotSame([], $rows);
        $row = Payload::obj($rows[0]);

        return ['ProductStyleId' => Payload::int($row['ProductStyleId']), 'PublicId' => Payload::str($row['PublicId'])];
    }

    /**
     * @param array{
     *   typeId:int,
     *   title:string,
     *   file:string,
     *   displayOrder:int,
     *   checksum:string,
     *   legacyId:string,
     *   isActive?:int
     * } $data
     */
    private function insertDocument(SqlConnection $db, array $data): int
    {
        $rows = $db->fetchAll(
            <<<'SQL'
INSERT INTO [technical].[Documents] (
    [DocumentTypeId], [Title], [Issuer], [OriginalFileName],
    [StoragePath], [ContentType], [FileExtension], [ChecksumSha256],
    [SourceSystemCode], [SourceLegacyId], [DisplayOrder], [IsActive]
)
OUTPUT INSERTED.[DocumentId] AS [DocumentId]
VALUES (
    :typeId, :title, N'TriCab', :file,
    :storagePath, N'application/pdf', N'pdf', :checksum,
    N'legacy-wp', :legacyId, :displayOrder, :isActive
)
SQL,
            [
                ':typeId' => $data['typeId'],
                ':title' => $data['title'],
                ':file' => $data['file'],
                ':storagePath' => 'products/technical/documents/fixture/' . $data['file'],
                ':checksum' => $data['checksum'],
                ':legacyId' => $data['legacyId'],
                ':displayOrder' => $data['displayOrder'],
                ':isActive' => $data['isActive'] ?? 1,
            ],
        );

        return Payload::int(Payload::obj($rows[0])['DocumentId']);
    }

    private function linkCategory(SqlConnection $db, int $documentId, int $categoryId): void
    {
        $db->execute(
            'INSERT INTO [technical].[DocumentCategoryLinks] ([DocumentId], [DocumentCategoryId]) VALUES (:d, :c)',
            [':d' => $documentId, ':c' => $categoryId],
        );
    }

    private function linkProduct(SqlConnection $db, int $documentId, int $productId): void
    {
        $db->execute(
            'INSERT INTO [technical].[DocumentProductLinks] ([DocumentId], [ProductId]) VALUES (:d, :p)',
            [':d' => $documentId, ':p' => $productId],
        );
    }

    private function linkStyle(SqlConnection $db, int $documentId, int $styleId): void
    {
        $db->execute(
            'INSERT INTO [technical].[DocumentStyleLinks] ([DocumentId], [ProductStyleId]) VALUES (:d, :s)',
            [':d' => $documentId, ':s' => $styleId],
        );
    }
}
