<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Canonical;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class CanonicalSlug
{
    public function __construct(
        public readonly string $legacySlug,
        public readonly ?string $legacyPath,
        public readonly ?string $legacyFullUrl,
        public readonly ?string $newSlug,
        public readonly ?string $regionCode,
        public readonly bool $redirectRequired,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'legacySlug' => $this->legacySlug,
            'legacyPath' => $this->legacyPath,
            'legacyFullUrl' => $this->legacyFullUrl,
            'newSlug' => $this->newSlug,
            'regionCode' => $this->regionCode,
            'redirectRequired' => $this->redirectRequired,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            legacySlug: MigrationPayload::string($data['legacySlug'] ?? ''),
            legacyPath: MigrationPayload::stringOrNull($data['legacyPath'] ?? null),
            legacyFullUrl: MigrationPayload::stringOrNull($data['legacyFullUrl'] ?? null),
            newSlug: MigrationPayload::stringOrNull($data['newSlug'] ?? null),
            regionCode: MigrationPayload::stringOrNull($data['regionCode'] ?? null),
            redirectRequired: MigrationPayload::bool($data['redirectRequired'] ?? false),
        );
    }
}
