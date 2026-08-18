<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Canonical;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class CanonicalRegionalContent
{
    public function __construct(
        public readonly string $regionCode,
        public readonly ?string $title,
        public readonly ?string $bodyHtml,
        public readonly ?string $marketCode = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'regionCode' => $this->regionCode,
            'title' => $this->title,
            'bodyHtml' => $this->bodyHtml,
            'marketCode' => $this->marketCode,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            regionCode: MigrationPayload::string($data['regionCode'] ?? ''),
            title: MigrationPayload::stringOrNull($data['title'] ?? null),
            bodyHtml: MigrationPayload::stringOrNull($data['bodyHtml'] ?? null),
            marketCode: MigrationPayload::stringOrNull($data['marketCode'] ?? null),
        );
    }
}
