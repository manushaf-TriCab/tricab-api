<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Canonical;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class CanonicalDocument
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly ?string $documentTypeCode,
        public readonly ?string $title,
        public readonly ?string $legacyPath,
        public readonly ?string $legacyUrl,
        public readonly ?string $originalFileName,
        public readonly ?string $checksum,
        public readonly bool $sourceExists,
        public readonly ?string $languageCode = null,
        public readonly ?string $regionCode = null,
        public readonly string $classificationOutcome = 'pending',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceKey' => $this->sourceKey,
            'documentTypeCode' => $this->documentTypeCode,
            'title' => $this->title,
            'legacyPath' => $this->legacyPath,
            'legacyUrl' => $this->legacyUrl,
            'originalFileName' => $this->originalFileName,
            'checksum' => $this->checksum,
            'sourceExists' => $this->sourceExists,
            'languageCode' => $this->languageCode,
            'regionCode' => $this->regionCode,
            'classificationOutcome' => $this->classificationOutcome,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceKey: MigrationPayload::string($data['sourceKey'] ?? ''),
            documentTypeCode: MigrationPayload::stringOrNull($data['documentTypeCode'] ?? null),
            title: MigrationPayload::stringOrNull($data['title'] ?? null),
            legacyPath: MigrationPayload::stringOrNull($data['legacyPath'] ?? null),
            legacyUrl: MigrationPayload::stringOrNull($data['legacyUrl'] ?? null),
            originalFileName: MigrationPayload::stringOrNull($data['originalFileName'] ?? null),
            checksum: MigrationPayload::stringOrNull($data['checksum'] ?? null),
            sourceExists: MigrationPayload::bool($data['sourceExists'] ?? false),
            languageCode: MigrationPayload::stringOrNull($data['languageCode'] ?? null),
            regionCode: MigrationPayload::stringOrNull($data['regionCode'] ?? null),
            classificationOutcome: MigrationPayload::string($data['classificationOutcome'] ?? 'pending', 'pending'),
        );
    }
}
