<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Migration;

use RuntimeException;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Technical\Document\TechnicalDocumentPathBuilder;
use TriCab\Api\Technical\Persistence\AzureSqlTechnicalDocumentLibraryRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Phase 4H — migrate approved Technical Document Library candidates only.
 *
 * Write sequence per document:
 * 1. Resolve/reuse PublicId
 * 2. Upload blob bytes
 * 3. Verify blob readable + size
 * 4. Insert Documents + links in one SQL transaction
 * 5. On DB failure: delete only the blob created in this attempt
 */
final class Phase4HTechnicalDocumentMigrator
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly ProductBlobStore $blobStore,
        private readonly AzureSqlTechnicalDocumentLibraryRepository $library,
        private readonly TechnicalDocumentPathBuilder $paths,
        private readonly string $container,
        private readonly string $sourceCacheDir,
    ) {
    }

    /**
     * @param array<string, mixed> $candidate frozen allow-list row (merged plan+v2)
     * @return array<string, mixed>
     */
    public function migrateOne(array $candidate, bool $dryRun = false): array
    {
        $legacyId = (string) $candidate['legacyId'];
        $shaExpected = strtolower((string) $candidate['sha256']);
        $filename = (string) $candidate['filename'];
        $title = $this->cleanTitle((string) ($candidate['legacyTitle'] ?? $filename));
        $typeCode = (string) ($candidate['targetDocumentType'] ?? 'certificate');
        $categories = $candidate['technicalCategories'] ?? [];
        if (!is_array($categories) || $categories === []) {
            $fam = (string) ($candidate['family'] ?? '');
            $categories = $fam === '' ? [] : array_values(array_filter(explode('+', $fam)));
        }
        $v2Action = (string) ($candidate['v2Action'] ?? '');
        $stylePublicIds = array_values(array_filter(array_map(
            static fn ($v): string => strtolower((string) $v),
            is_array($candidate['newProductStyleIds'] ?? null) ? $candidate['newProductStyleIds'] : [],
        )));
        $wantStyle = str_contains($v2Action, 'CREATE_STYLE_LINK');
        $wantProduct = str_contains($v2Action, 'CREATE_PRODUCT_LINK');

        $existing = $this->library->findActiveByChecksum($shaExpected)
            ?? $this->library->findActiveByLegacyId('legacy-wp', $legacyId);

        if ($existing !== null) {
            $linkResult = $dryRun
                ? ['categoryLinksAdded' => 0, 'styleLinksAdded' => 0, 'productLinksAdded' => 0]
                : $this->ensureLinksForExisting(
                    $existing->publicId,
                    $categories,
                    $wantStyle ? $stylePublicIds : [],
                    $wantProduct ? $stylePublicIds : [],
                );

            return [
                'status' => 'REUSE_EXISTING',
                'legacyId' => $legacyId,
                'publicId' => $existing->publicId,
                'sha256' => $shaExpected,
                'blobUploaded' => false,
                'links' => $linkResult,
            ];
        }

        $bytes = $this->loadValidatedBytes($candidate);
        $size = strlen($bytes);
        $shaActual = hash('sha256', $bytes);
        if ($shaActual !== $shaExpected) {
            return [
                'status' => 'SOURCE_DRIFT',
                'legacyId' => $legacyId,
                'reason' => 'SHA mismatch after load',
                'expectedSha' => $shaExpected,
                'actualSha' => $shaActual,
            ];
        }
        if (!str_starts_with($bytes, '%PDF')) {
            return [
                'status' => 'EXECUTION_FAILED',
                'legacyId' => $legacyId,
                'reason' => 'Not a valid PDF payload',
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'DRY_RUN_OK',
                'legacyId' => $legacyId,
                'sha256' => $shaActual,
                'size' => $size,
                'categories' => $categories,
                'stylePublicIds' => $wantStyle ? $stylePublicIds : [],
                'productLinkStyles' => $wantProduct ? $stylePublicIds : [],
            ];
        }

        $publicId = $this->newGuid();
        $safeName = $this->safeFileName($filename);
        $storagePath = $this->paths->documentPath($publicId, $safeName);
        $blobCreated = false;

        try {
            $this->blobStore->putObject($this->container, $storagePath, 'application/pdf', $bytes);
            $blobCreated = true;
            $head = $this->blobStore->headObject($this->container, $storagePath);
            if ($head === null) {
                throw new RuntimeException('Blob HEAD missing after putObject');
            }
            $stored = $this->blobStore->getObjectBinary($this->container, $storagePath);
            if ($stored === null || hash('sha256', $stored) !== $shaActual) {
                throw new RuntimeException('Blob SHA verification failed after upload');
            }
            if (strlen($stored) === 0) {
                throw new RuntimeException('Zero-byte blob after upload');
            }

            $publicUrl = $this->blobStore->buildPublicUrl($this->container, $storagePath);
            $typeId = $this->requireDocumentTypeId($typeCode);
            $categoryIds = $this->requireCategoryIds($categories);
            $styleIds = $wantStyle ? $this->resolveStyleIds($stylePublicIds) : [];
            $productIds = $wantProduct ? $this->resolveProductIdsForStyles($stylePublicIds) : [];

            $this->db->beginTransaction();
            try {
                $docId = $this->insertDocument([
                    'publicId' => $publicId,
                    'typeId' => $typeId,
                    'title' => $title,
                    'issuer' => $this->nullableString($candidate['issuer'] ?? null),
                    'originalFileName' => $filename,
                    'storagePath' => $storagePath,
                    'publicUrl' => $publicUrl,
                    'byteSize' => $size,
                    'checksum' => $shaActual,
                    'legacyId' => $legacyId,
                ]);
                $categoryLinkIds = [];
                foreach ($categoryIds as $categoryId) {
                    $categoryLinkIds[] = $this->insertCategoryLink($docId, $categoryId);
                }
                $styleLinkIds = [];
                foreach ($styleIds as $styleId) {
                    $styleLinkIds[] = $this->insertStyleLink($docId, $styleId);
                }
                $productLinkIds = [];
                foreach ($productIds as $productId) {
                    $productLinkIds[] = $this->insertProductLink($docId, $productId);
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            return [
                'status' => 'CREATED',
                'legacyId' => $legacyId,
                'publicId' => $publicId,
                'documentId' => $docId,
                'sha256' => $shaActual,
                'size' => $size,
                'container' => $this->container,
                'storagePath' => $storagePath,
                'publicUrl' => $publicUrl,
                'categoryLinkIds' => $categoryLinkIds,
                'styleLinkIds' => $styleLinkIds,
                'productLinkIds' => $productLinkIds,
                'blobUploaded' => true,
            ];
        } catch (\Throwable $e) {
            if ($blobCreated) {
                try {
                    $this->blobStore->deleteObject($this->container, $storagePath);
                } catch (\Throwable) {
                    // best effort — recorded as failure below
                }
            }

            return [
                'status' => 'EXECUTION_FAILED',
                'legacyId' => $legacyId,
                'reason' => $e->getMessage(),
                'storagePath' => $storagePath,
                'blobCleaned' => $blobCreated,
            ];
        }
    }

    /**
     * @param array<string, mixed> $candidate
     */
    public function revalidateSource(array $candidate): array
    {
        try {
            $bytes = $this->loadValidatedBytes($candidate);
        } catch (\Throwable $e) {
            return [
                'legacyId' => (string) $candidate['legacyId'],
                'ok' => false,
                'reason' => $e->getMessage(),
            ];
        }
        $expectedSha = strtolower((string) $candidate['sha256']);
        $expectedSize = (int) ($candidate['size'] ?? 0);
        $actualSha = hash('sha256', $bytes);
        $actualSize = strlen($bytes);
        $pdfValid = str_starts_with($bytes, '%PDF');
        $ok = $pdfValid && $actualSha === $expectedSha && ($expectedSize === 0 || $actualSize === $expectedSize);

        return [
            'legacyId' => (string) $candidate['legacyId'],
            'ok' => $ok,
            'pdfValid' => $pdfValid,
            'expectedSha' => $expectedSha,
            'actualSha' => $actualSha,
            'expectedSize' => $expectedSize,
            'actualSize' => $actualSize,
            'reason' => $ok ? null : 'SOURCE_DRIFT',
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function loadValidatedBytes(array $candidate): string
    {
        $sha = strtolower((string) $candidate['sha256']);
        $cacheFile = rtrim($this->sourceCacheDir, '/') . '/' . $sha . '.pdf';
        if (is_file($cacheFile)) {
            $bytes = file_get_contents($cacheFile);
            if ($bytes !== false && hash('sha256', $bytes) === $sha) {
                return $bytes;
            }
        }

        $url = (string) ($candidate['sourceUrl'] ?? '');
        if ($url === '' || !str_starts_with($url, 'https://www.tricab.com/')) {
            throw new RuntimeException('Missing or non-TriCab sourceUrl');
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 90,
                'header' => "User-Agent: TriCab-Phase4H-Migration/1.0\r\n",
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $bytes = @file_get_contents($url, false, $ctx);
        if ($bytes === false) {
            throw new RuntimeException('Unable to fetch TriCab sourceUrl');
        }
        if (str_contains($bytes, 'logbox=true') || str_contains(strtolower($bytes), '<html')) {
            // login gate HTML is not a PDF
            if (!str_starts_with($bytes, '%PDF')) {
                throw new RuntimeException('LOGIN_GATED_OR_HTML_RESPONSE');
            }
        }
        if (!is_dir($this->sourceCacheDir)) {
            mkdir($this->sourceCacheDir, 0775, true);
        }
        file_put_contents($cacheFile, $bytes);

        return $bytes;
    }

    /**
     * @param list<string> $categories
     * @param list<string> $stylePublicIds
     * @param list<string> $productStylePublicIds
     * @return array{categoryLinksAdded:int, styleLinksAdded:int, productLinksAdded:int}
     */
    private function ensureLinksForExisting(
        string $publicId,
        array $categories,
        array $stylePublicIds,
        array $productStylePublicIds,
    ): array {
        $doc = $this->db->fetchAll(
            'SELECT [DocumentId] FROM [technical].[Documents] WHERE [PublicId] = :p AND [DeletedAtUtc] IS NULL',
            [':p' => $publicId],
        );
        if ($doc === []) {
            throw new RuntimeException('Existing document publicId not found in DB');
        }
        $docId = (int) $doc[0]['DocumentId'];
        $addedCat = 0;
        $addedStyle = 0;
        $addedProd = 0;

        foreach ($this->requireCategoryIds($categories) as $categoryId) {
            $exists = $this->db->fetchAll(
                'SELECT 1 AS x FROM [technical].[DocumentCategoryLinks] WHERE [DocumentId]=:d AND [DocumentCategoryId]=:c AND [DeletedAtUtc] IS NULL',
                [':d' => $docId, ':c' => $categoryId],
            );
            if ($exists === []) {
                $this->insertCategoryLink($docId, $categoryId);
                $addedCat++;
            }
        }
        foreach ($this->resolveStyleIds($stylePublicIds) as $styleId) {
            $exists = $this->db->fetchAll(
                'SELECT 1 AS x FROM [technical].[DocumentStyleLinks] WHERE [DocumentId]=:d AND [ProductStyleId]=:s AND [DeletedAtUtc] IS NULL',
                [':d' => $docId, ':s' => $styleId],
            );
            if ($exists === []) {
                $this->insertStyleLink($docId, $styleId);
                $addedStyle++;
            }
        }
        foreach ($this->resolveProductIdsForStyles($productStylePublicIds) as $productId) {
            $exists = $this->db->fetchAll(
                'SELECT 1 AS x FROM [technical].[DocumentProductLinks] WHERE [DocumentId]=:d AND [ProductId]=:p AND [DeletedAtUtc] IS NULL',
                [':d' => $docId, ':p' => $productId],
            );
            if ($exists === []) {
                $this->insertProductLink($docId, $productId);
                $addedProd++;
            }
        }

        return [
            'categoryLinksAdded' => $addedCat,
            'styleLinksAdded' => $addedStyle,
            'productLinksAdded' => $addedProd,
        ];
    }

    /**
     * @param array{
     *   publicId:string,typeId:int,title:string,issuer:?string,originalFileName:string,
     *   storagePath:string,publicUrl:?string,byteSize:int,checksum:string,legacyId:string
     * } $data
     */
    private function insertDocument(array $data): int
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
INSERT INTO [technical].[Documents] (
    [PublicId], [DocumentTypeId], [Title], [Issuer], [OriginalFileName],
    [StorageProviderCode], [StorageContainer], [StoragePath], [PublicUrl],
    [ContentType], [FileExtension], [ByteSize], [ChecksumSha256],
    [AccessCode], [SourceSystemCode], [SourceLegacyId], [DisplayOrder], [IsActive]
)
OUTPUT INSERTED.[DocumentId] AS [DocumentId]
VALUES (
    :publicId, :typeId, :title, :issuer, :originalFileName,
    N'azure-blob', :container, :storagePath, :publicUrl,
    N'application/pdf', N'pdf', :byteSize, :checksum,
    N'authenticated', N'legacy-wp', :legacyId, 0, 1
)
SQL,
            [
                ':publicId' => $data['publicId'],
                ':typeId' => $data['typeId'],
                ':title' => $data['title'],
                ':issuer' => $data['issuer'],
                ':originalFileName' => $data['originalFileName'],
                ':container' => $this->container,
                ':storagePath' => $data['storagePath'],
                ':publicUrl' => $data['publicUrl'],
                ':byteSize' => $data['byteSize'],
                ':checksum' => $data['checksum'],
                ':legacyId' => $data['legacyId'],
            ],
        );

        return (int) $rows[0]['DocumentId'];
    }

    private function insertCategoryLink(int $documentId, int $categoryId): int
    {
        $rows = $this->db->fetchAll(
            'INSERT INTO [technical].[DocumentCategoryLinks] ([DocumentId], [DocumentCategoryId]) OUTPUT INSERTED.[DocumentCategoryLinkId] AS [Id] VALUES (:d, :c)',
            [':d' => $documentId, ':c' => $categoryId],
        );

        return (int) $rows[0]['Id'];
    }

    private function insertStyleLink(int $documentId, int $styleId): int
    {
        $rows = $this->db->fetchAll(
            'INSERT INTO [technical].[DocumentStyleLinks] ([DocumentId], [ProductStyleId]) OUTPUT INSERTED.[DocumentStyleLinkId] AS [Id] VALUES (:d, :s)',
            [':d' => $documentId, ':s' => $styleId],
        );

        return (int) $rows[0]['Id'];
    }

    private function insertProductLink(int $documentId, int $productId): int
    {
        $rows = $this->db->fetchAll(
            'INSERT INTO [technical].[DocumentProductLinks] ([DocumentId], [ProductId]) OUTPUT INSERTED.[DocumentProductLinkId] AS [Id] VALUES (:d, :p)',
            [':d' => $documentId, ':p' => $productId],
        );

        return (int) $rows[0]['Id'];
    }

    private function requireDocumentTypeId(string $code): int
    {
        $rows = $this->db->fetchAll(
            'SELECT [DocumentTypeId] FROM [product].[DocumentTypes] WHERE [DocumentTypeCode]=:c AND [DeletedAtUtc] IS NULL',
            [':c' => $code],
        );
        if ($rows === []) {
            throw new RuntimeException('Missing DocumentTypeCode: ' . $code);
        }

        return (int) $rows[0]['DocumentTypeId'];
    }

    /**
     * @param list<string>|array<int, mixed> $codes
     * @return list<int>
     */
    private function requireCategoryIds(array $codes): array
    {
        $ids = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            $rows = $this->db->fetchAll(
                'SELECT [DocumentCategoryId] FROM [technical].[DocumentCategories] WHERE [CategoryCode]=:c AND [DeletedAtUtc] IS NULL AND [IsActive]=1',
                [':c' => $code],
            );
            if ($rows === []) {
                throw new RuntimeException('Missing DocumentCategory: ' . $code);
            }
            $ids[] = (int) $rows[0]['DocumentCategoryId'];
        }

        return $ids;
    }

    /**
     * @param list<string> $stylePublicIds
     * @return list<int>
     */
    private function resolveStyleIds(array $stylePublicIds): array
    {
        $ids = [];
        foreach ($stylePublicIds as $publicId) {
            $rows = $this->db->fetchAll(
                'SELECT [ProductStyleId] FROM [product].[ProductStyles] WHERE [PublicId]=:p AND [DeletedAtUtc] IS NULL',
                [':p' => $publicId],
            );
            if ($rows === []) {
                throw new RuntimeException('Missing ProductStyle PublicId: ' . $publicId);
            }
            $ids[] = (int) $rows[0]['ProductStyleId'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $stylePublicIds
     * @return list<int>
     */
    private function resolveProductIdsForStyles(array $stylePublicIds): array
    {
        $productIds = [];
        foreach ($stylePublicIds as $publicId) {
            $rows = $this->db->fetchAll(
                <<<'SQL'
SELECT DISTINCT p.[ProductId]
FROM [product].[ProductStyles] s
INNER JOIN [product].[ProductStyleAssignments] a ON a.[ProductStyleId] = s.[ProductStyleId]
INNER JOIN [product].[Products] p ON p.[ProductId] = a.[ProductId] AND p.[DeletedAtUtc] IS NULL
WHERE s.[PublicId] = :p AND s.[DeletedAtUtc] IS NULL
SQL,
                [':p' => $publicId],
            );
            foreach ($rows as $row) {
                $productIds[] = (int) $row['ProductId'];
            }
        }

        return array_values(array_unique($productIds));
    }

    private function newGuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function safeFileName(string $filename): string
    {
        $base = basename(str_replace(["\0", '\\'], '', $filename));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? 'document.pdf';
        $base = trim($base, '.-_');
        if ($base === '') {
            $base = 'document.pdf';
        }
        if (!str_ends_with(strtolower($base), '.pdf')) {
            $base .= '.pdf';
        }

        return $base;
    }

    private function cleanTitle(string $title): string
    {
        $title = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $title)));
        $title = preg_replace('/\s+/', ' ', trim($title)) ?? '';
        if ($title === '') {
            $title = 'Untitled document';
        }

        return mb_substr($title, 0, 200);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 200);
    }
}
