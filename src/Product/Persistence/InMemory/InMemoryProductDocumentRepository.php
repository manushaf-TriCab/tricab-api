<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * In-memory product document repository. {@see archive()} soft-deletes document metadata only.
 */
final class InMemoryProductDocumentRepository implements ProductDocumentRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId, bool $publicOnly = false): array
    {
        $items = [];
        foreach ($this->store->documents() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString() || ($row['archived'] ?? false)) {
                continue;
            }
            if ($publicOnly && ($row['accessCode'] ?? '') !== 'public') {
                continue;
            }
            $items[] = $this->toSummary($row);
        }

        return $items;
    }

    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentSummary {
        $row = [
            'publicId' => $this->store->newPublicId(),
            'productPublicId' => $productPublicId->toString(),
            'documentTypeCode' => $data['documentTypeCode'],
            'title' => $data['title'],
            'versionLabel' => $data['versionLabel'] ?? null,
            'languageCode' => $data['languageCode'] ?? null,
            'publicUrl' => $data['publicUrl'] ?? null,
            'accessCode' => $data['accessCode'],
            'displayOrder' => $data['displayOrder'] ?? 0,
            'archived' => false,
        ];
        $this->store->addDocument($row);

        return $this->toSummary($row);
    }

    public function archive(
        ProductPublicId $productPublicId,
        string $documentPublicId,
        UserId $actorUserId,
    ): void {
        foreach ($this->store->documents() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId->toString()
                && ($row['publicId'] ?? '') === $documentPublicId
            ) {
                $row['archived'] = true;
                $this->store->addDocument($row);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSummary(array $row): ProductDocumentSummary
    {
        return new ProductDocumentSummary(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            documentTypeCode: AzureSqlValueMapper::stringValue($row['documentTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['title'] ?? ''),
            versionLabel: AzureSqlValueMapper::nullableString($row['versionLabel'] ?? null),
            languageCode: AzureSqlValueMapper::nullableString($row['languageCode'] ?? null),
            publicUrl: AzureSqlValueMapper::nullableString($row['publicUrl'] ?? null),
            accessCode: AzureSqlValueMapper::stringValue($row['accessCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
        );
    }
}
