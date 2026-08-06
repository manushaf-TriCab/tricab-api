<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductDocumentFileRecord;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/** Future Azure SQL: ProductDocuments / ProductCADFiles / ProductDownloads */
interface ProductDocumentRepository
{
    /**
     * @return list<ProductDocumentSummary>
     */
    public function listByProduct(ProductPublicId $productPublicId, bool $publicOnly = false): array;

    public function findByPublicId(string $documentPublicId): ?ProductDocumentFileRecord;

    /**
     * @param array{
     *   documentTypeCode: string,
     *   title: string,
     *   storagePath: string,
     *   accessCode: string,
     *   versionLabel?: ?string,
     *   languageCode?: ?string,
     *   regionPublicId?: ?string,
     *   marketPublicId?: ?string,
     *   effectiveFromUtc?: ?string,
     *   effectiveToUtc?: ?string,
     *   displayOrder?: int,
     *   publicUrl?: ?string
     * } $data
     */
    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentSummary;

    /**
     * @param array{
     *   documentTypeCode: string,
     *   title: string,
     *   accessCode: string,
     *   originalFileName: string,
     *   contentType: string,
     *   byteSize: int,
     *   fileExtension: string,
     *   storagePath: string,
     *   storageContainer: string,
     *   uploadStatusCode: string,
     *   scanStatusCode: string,
     *   publicId?: string,
     *   versionLabel?: ?string,
     *   languageCode?: ?string,
     *   displayOrder?: int
     * } $data
     */
    public function createUploadSession(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentFileRecord;

    /**
     * @param array{
     *   uploadStatusCode: string,
     *   scanStatusCode: string,
     *   publicUrl?: ?string,
     *   contentType?: ?string,
     *   byteSize?: ?int,
     *   etag?: ?string,
     *   storagePath?: ?string,
     *   storageContainer?: ?string
     * } $blobMeta
     */
    public function markUploadCompleted(
        string $documentPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): ProductDocumentFileRecord;

    /**
     * @param array{
     *   originalFileName: string,
     *   contentType: string,
     *   byteSize: int,
     *   fileExtension: string,
     *   storagePath: string,
     *   storageContainer: string,
     *   uploadStatusCode: string,
     *   scanStatusCode: string
     * } $data
     */
    public function beginReplaceUpload(
        string $documentPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentFileRecord;

    public function clearFile(string $documentPublicId, UserId $actorUserId): void;

    public function archive(
        ProductPublicId $productPublicId,
        string $documentPublicId,
        UserId $actorUserId,
    ): void;
}
