<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Canonical;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class CanonicalMedia
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly ?string $legacyPath,
        public readonly ?string $legacyUrl,
        public readonly ?string $originalFileName,
        public readonly ?string $roleCode,
        public readonly ?string $checksum,
        public readonly bool $sourceExists,
        public readonly ?string $regionCode = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceKey' => $this->sourceKey,
            'legacyPath' => $this->legacyPath,
            'legacyUrl' => $this->legacyUrl,
            'originalFileName' => $this->originalFileName,
            'roleCode' => $this->roleCode,
            'checksum' => $this->checksum,
            'sourceExists' => $this->sourceExists,
            'regionCode' => $this->regionCode,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceKey: MigrationPayload::string($data['sourceKey'] ?? ''),
            legacyPath: MigrationPayload::stringOrNull($data['legacyPath'] ?? null),
            legacyUrl: MigrationPayload::stringOrNull($data['legacyUrl'] ?? null),
            originalFileName: MigrationPayload::stringOrNull($data['originalFileName'] ?? null),
            roleCode: MigrationPayload::stringOrNull($data['roleCode'] ?? null),
            checksum: MigrationPayload::stringOrNull($data['checksum'] ?? null),
            sourceExists: MigrationPayload::bool($data['sourceExists'] ?? false),
            regionCode: MigrationPayload::stringOrNull($data['regionCode'] ?? null),
        );
    }
}
