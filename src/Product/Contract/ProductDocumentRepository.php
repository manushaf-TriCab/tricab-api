<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/** Future Azure SQL: ProductDocuments / ProductCADFiles / ProductDownloads */
interface ProductDocumentRepository
{
    /**
     * @return list<ProductDocumentSummary>
     */
    public function listByProduct(ProductPublicId $productPublicId, bool $publicOnly = false): array;

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

    public function archive(
        ProductPublicId $productPublicId,
        string $documentPublicId,
        UserId $actorUserId,
    ): void;
}
