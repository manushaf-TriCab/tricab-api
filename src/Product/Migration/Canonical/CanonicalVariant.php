<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Canonical;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class CanonicalVariant
{
    /** @param list<CanonicalSpecificationValue> $specifications */
    public function __construct(
        public readonly string $sourceKey,
        public readonly ?string $sku,
        public readonly ?string $productCode,
        public readonly ?string $name,
        public readonly ?string $legacyStyleCode,
        public readonly array $specifications = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceKey' => $this->sourceKey,
            'sku' => $this->sku,
            'productCode' => $this->productCode,
            'name' => $this->name,
            'legacyStyleCode' => $this->legacyStyleCode,
            'specifications' => array_map(static fn (CanonicalSpecificationValue $s) => $s->toArray(), $this->specifications),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $specs = [];
        $rawSpecs = $data['specifications'] ?? null;
        if (is_array($rawSpecs)) {
            foreach ($rawSpecs as $s) {
                if (is_array($s)) {
                    $specs[] = CanonicalSpecificationValue::fromArray(MigrationPayload::assoc($s));
                }
            }
        }

        return new self(
            sourceKey: MigrationPayload::string($data['sourceKey'] ?? ''),
            sku: MigrationPayload::stringOrNull($data['sku'] ?? null),
            productCode: MigrationPayload::stringOrNull($data['productCode'] ?? null),
            name: MigrationPayload::stringOrNull($data['name'] ?? null),
            legacyStyleCode: MigrationPayload::stringOrNull($data['legacyStyleCode'] ?? null),
            specifications: $specs,
        );
    }
}
