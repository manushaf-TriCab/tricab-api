<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Document;

/**
 * Read model for a Technical library document (canonical file + associations).
 */
final class TechnicalDocumentRecord
{
    /**
     * @param list<string> $categoryCodes
     * @param list<string> $productPublicIds
     * @param list<string> $stylePublicIds
     */
    public function __construct(
        public readonly string $publicId,
        public readonly string $title,
        public readonly string $originalFileName,
        public readonly string $documentTypeCode,
        public readonly array $categoryCodes,
        public readonly ?string $issuer = null,
        public readonly ?string $storageContainer = null,
        public readonly ?string $storagePath = null,
        public readonly ?string $publicUrl = null,
        public readonly ?string $checksumSha256 = null,
        public readonly ?string $sourceLegacyId = null,
        public readonly array $productPublicIds = [],
        public readonly array $stylePublicIds = [],
        public readonly bool $isActive = true,
    ) {
    }

    /**
     * API item shape compatible with existing TechnicalDocumentBrowser clients.
     *
     * Client-accessible `url` is resolved at response time (short-lived SAS) by
     * ResolveTechnicalDocumentsQuery — never from persisted PublicUrl alone.
     *
     * @return array<string, mixed>
     */
    public function toCatalogItem(string $primaryCategory, ?string $clientUrl = null): array
    {
        $item = [
            'id' => $this->publicId,
            'title' => $this->title,
            'filename' => $this->originalFileName,
            'documentTypeCode' => $this->documentTypeCode,
            'family' => $primaryCategory,
            'source' => 'technical-document-library',
            'migrationStatus' => 'LIBRARY',
        ];
        if ($this->issuer !== null && $this->issuer !== '') {
            $item['issuer'] = $this->issuer;
        }
        if ($clientUrl !== null && $clientUrl !== '') {
            $item['url'] = $clientUrl;
        }
        if ($this->storagePath !== null && $this->storagePath !== '') {
            $item['storagePath'] = $this->storagePath;
        }
        if ($this->checksumSha256 !== null && $this->checksumSha256 !== '') {
            $item['checksumSha256'] = $this->checksumSha256;
        }
        if ($this->sourceLegacyId !== null && $this->sourceLegacyId !== '') {
            $item['sourceLegacyId'] = $this->sourceLegacyId;
        }
        if ($this->productPublicIds !== []) {
            $item['productPublicIds'] = $this->productPublicIds;
        }
        if ($this->stylePublicIds !== []) {
            $item['stylePublicIds'] = $this->stylePublicIds;
        }

        return $item;
    }
}
