<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Canonical;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Migration-layer product record (not a Product domain entity).
 */
final class CanonicalProduct
{
    /**
     * @param list<CanonicalVariant> $variants
     * @param list<CanonicalSpecificationValue> $specifications
     * @param list<CanonicalMedia> $media
     * @param list<CanonicalDocument> $documents
     * @param list<CanonicalRelationship> $relationships
     * @param list<string> $regions
     * @param list<CanonicalSlug> $slugs
     * @param list<CanonicalRegionalContent> $regionalContent
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly ?string $legacyStyleCode,
        public readonly string $name,
        public readonly ?string $slug,
        public readonly ?string $familyCode,
        public readonly ?string $categoryCode,
        public readonly ?string $seriesCode,
        public readonly ?string $shortDescription,
        public readonly ?string $fullDescription,
        public readonly string $status,
        public readonly array $regions,
        public readonly array $variants,
        public readonly array $specifications,
        public readonly array $media,
        public readonly array $documents,
        public readonly array $relationships,
        public readonly array $slugs = [],
        public readonly array $regionalContent = [],
        public readonly ?string $legacyPostType = null,
        public readonly ?string $productCode = null,
        public readonly ?string $productDecisionCode = null,
        public readonly ?string $productDecisionReason = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceKey' => $this->sourceKey,
            'legacyStyleCode' => $this->legacyStyleCode,
            'name' => $this->name,
            'slug' => $this->slug,
            'familyCode' => $this->familyCode,
            'categoryCode' => $this->categoryCode,
            'seriesCode' => $this->seriesCode,
            'shortDescription' => $this->shortDescription,
            'fullDescription' => $this->fullDescription,
            'status' => $this->status,
            'regions' => $this->regions,
            'productCode' => $this->productCode,
            'legacyPostType' => $this->legacyPostType,
            'productDecisionCode' => $this->productDecisionCode,
            'productDecisionReason' => $this->productDecisionReason,
            'variants' => array_map(static fn (CanonicalVariant $v) => $v->toArray(), $this->variants),
            'specifications' => array_map(static fn (CanonicalSpecificationValue $s) => $s->toArray(), $this->specifications),
            'media' => array_map(static fn (CanonicalMedia $m) => $m->toArray(), $this->media),
            'documents' => array_map(static fn (CanonicalDocument $d) => $d->toArray(), $this->documents),
            'relationships' => array_map(static fn (CanonicalRelationship $r) => $r->toArray(), $this->relationships),
            'slugs' => array_map(static fn (CanonicalSlug $s) => $s->toArray(), $this->slugs),
            'regionalContent' => array_map(static fn (CanonicalRegionalContent $c) => $c->toArray(), $this->regionalContent),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceKey: MigrationPayload::string($data['sourceKey'] ?? ''),
            legacyStyleCode: MigrationPayload::stringOrNull($data['legacyStyleCode'] ?? null),
            name: MigrationPayload::string($data['name'] ?? ''),
            slug: MigrationPayload::stringOrNull($data['slug'] ?? null),
            familyCode: MigrationPayload::stringOrNull($data['familyCode'] ?? null),
            categoryCode: MigrationPayload::stringOrNull($data['categoryCode'] ?? null),
            seriesCode: MigrationPayload::stringOrNull($data['seriesCode'] ?? null),
            shortDescription: MigrationPayload::stringOrNull($data['shortDescription'] ?? null),
            fullDescription: MigrationPayload::stringOrNull($data['fullDescription'] ?? null),
            status: MigrationPayload::string($data['status'] ?? 'draft', 'draft'),
            regions: MigrationPayload::stringList($data['regions'] ?? null, []),
            variants: self::mapList($data['variants'] ?? null, static fn (array $v): CanonicalVariant => CanonicalVariant::fromArray($v)),
            specifications: self::mapList($data['specifications'] ?? null, static fn (array $s): CanonicalSpecificationValue => CanonicalSpecificationValue::fromArray($s)),
            media: self::mapList($data['media'] ?? null, static fn (array $m): CanonicalMedia => CanonicalMedia::fromArray($m)),
            documents: self::mapList($data['documents'] ?? null, static fn (array $d): CanonicalDocument => CanonicalDocument::fromArray($d)),
            relationships: self::mapList($data['relationships'] ?? null, static fn (array $r): CanonicalRelationship => CanonicalRelationship::fromArray($r)),
            slugs: self::mapList($data['slugs'] ?? null, static fn (array $s): CanonicalSlug => CanonicalSlug::fromArray($s)),
            regionalContent: self::mapList($data['regionalContent'] ?? null, static fn (array $c): CanonicalRegionalContent => CanonicalRegionalContent::fromArray($c)),
            legacyPostType: MigrationPayload::stringOrNull($data['legacyPostType'] ?? null),
            productCode: MigrationPayload::stringOrNull($data['productCode'] ?? null),
            productDecisionCode: MigrationPayload::stringOrNull($data['productDecisionCode'] ?? null),
            productDecisionReason: MigrationPayload::stringOrNull($data['productDecisionReason'] ?? null),
        );
    }

    /**
     * @template T
     * @param mixed $value
     * @param callable(array<string, mixed>): T $mapper
     * @return list<T>
     */
    private static function mapList(mixed $value, callable $mapper): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $mapper(MigrationPayload::assoc($item));
            }
        }

        return $out;
    }
}
