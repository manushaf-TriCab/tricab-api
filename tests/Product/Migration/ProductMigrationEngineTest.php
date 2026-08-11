<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\DTO\LegacyProductMappingDetails;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;
use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;
use TriCab\Core\Product\Migration\Identity\EmptyMigrationLookupIndex;
use TriCab\Core\Product\Migration\Identity\ProductIdentityMatchResult;
use TriCab\Core\Product\Migration\Identity\ProductIdentityMatcher;
use TriCab\Core\Product\Migration\Identity\ProductVariantClassifier;
use TriCab\Core\Product\Migration\Mapping\OptionAliasResolver;
use TriCab\Core\Product\Migration\Transform\SpecificationValueTransformer;

final class ProductMigrationEngineTest extends TestCase
{
    public function test_identity_matcher_never_uses_name_alone(): void
    {
        $matcher = new ProductIdentityMatcher(new NullLegacyMappings(), new EmptyMigrationLookupIndex());
        $product = new CanonicalProduct(
            sourceKey: 'wordpress:wp_posts:1',
            legacyStyleCode: null,
            name: 'FLEXI-TUFF',
            slug: null,
            familyCode: null,
            categoryCode: null,
            seriesCode: null,
            shortDescription: null,
            fullDescription: null,
            status: 'draft',
            regions: [],
            variants: [],
            specifications: [],
            media: [],
            documents: [],
            relationships: [],
        );
        $result = $matcher->match($product);
        self::assertTrue($result->requiresReview);
        self::assertSame(ProductIdentityMatchResult::CONFIDENCE_MANUAL_REVIEW, $result->matchConfidence);
        self::assertSame('none', $result->matchMethod);
    }

    public function test_identity_matcher_uses_existing_mapping(): void
    {
        $repo = new NullLegacyMappings();
        $repo->seed(new LegacyProductMappingDetails(
            legacyProductMappingId: 1,
            sourceSystemCode: 'wordpress',
            sourceProductKey: 'wp_posts:3812',
            productPublicId: '11111111-1111-1111-1111-111111111111',
            productVariantPublicId: null,
            migrationStatusCode: 'imported',
            migrationNotes: null,
            createdAtUtc: '2026-01-01T00:00:00Z',
            updatedAtUtc: '2026-01-01T00:00:00Z',
            migratedAtUtc: '2026-01-01T00:00:00Z',
        ));
        $matcher = new ProductIdentityMatcher($repo, new EmptyMigrationLookupIndex());
        $product = new CanonicalProduct(
            sourceKey: 'wordpress:wp_posts:3812',
            legacyStyleCode: 'FT',
            name: 'Anything',
            slug: 'anything',
            familyCode: null,
            categoryCode: null,
            seriesCode: null,
            shortDescription: null,
            fullDescription: null,
            status: 'draft',
            regions: [],
            variants: [],
            specifications: [],
            media: [],
            documents: [],
            relationships: [],
        );
        $result = $matcher->match($product);
        self::assertFalse($result->requiresReview);
        self::assertSame('legacy-product-mapping', $result->matchMethod);
        self::assertSame('11111111-1111-1111-1111-111111111111', $result->targetProductPublicId);
    }

    public function test_variant_classifier_wordpress_cable_is_product(): void
    {
        $c = new ProductVariantClassifier();
        $product = new CanonicalProduct(
            sourceKey: 'wordpress:wp_posts:1',
            legacyStyleCode: 'FT',
            name: 'X',
            slug: 'x',
            familyCode: null,
            categoryCode: null,
            seriesCode: null,
            shortDescription: null,
            fullDescription: null,
            status: 'draft',
            regions: [],
            variants: [],
            specifications: [],
            media: [],
            documents: [],
            relationships: [],
            legacyPostType: 'cable',
        );
        $d = $c->classify($product, 'cable');
        self::assertSame(ProductVariantClassifier::PRODUCT, $d['decision']);
    }

    public function test_variant_classifier_tc_style_is_variant(): void
    {
        $c = new ProductVariantClassifier();
        $product = new CanonicalProduct(
            sourceKey: 'tc:tpro:FT-1',
            legacyStyleCode: 'FT',
            name: 'row',
            slug: null,
            familyCode: null,
            categoryCode: null,
            seriesCode: null,
            shortDescription: null,
            fullDescription: null,
            status: 'draft',
            regions: [],
            variants: [],
            specifications: [],
            media: [],
            documents: [],
            relationships: [],
            legacyPostType: 'tpro',
        );
        $d = $c->classify($product, 'tpro');
        self::assertSame(ProductVariantClassifier::VARIANT, $d['decision']);
    }

    public function test_transformer_rejects_ambiguous_decimal(): void
    {
        $t = new SpecificationValueTransformer();
        $r = $t->transform('od', 'approximately 25mm', 'decimal', 'OUTER_DIAMETER', 'MM', 'variant');
        self::assertSame('manual-review', $r->outcome);
    }

    public function test_transformer_preserves_numeric_od(): void
    {
        $t = new SpecificationValueTransformer();
        $r = $t->transform('od', '25.4', 'decimal', 'OUTER_DIAMETER', 'MM', 'variant');
        self::assertSame('preserved', $r->outcome);
        self::assertSame('25.4', $r->transformedValue);
    }

    public function test_option_alias_resolution(): void
    {
        $a = new OptionAliasResolver();
        $a->register('CONDUCTOR', 'T/C', 'TINNED_COPPER');
        $a->register('CONDUCTOR', 'Tinned Cu', 'TINNED_COPPER');
        $a->register('CONDUCTOR', 'Tinned Copper', 'TINNED_COPPER');
        self::assertSame('TINNED_COPPER', $a->resolve('CONDUCTOR', 'tinned cu'));
        self::assertNull($a->resolve('CONDUCTOR', 'mystery alloy'));
    }
}

final class NullLegacyMappings implements LegacyProductMappingRepository
{
    /** @var array<string, LegacyProductMappingDetails> */
    private array $rows = [];

    public function seed(LegacyProductMappingDetails $details): void
    {
        $this->rows[strtolower($details->sourceSystemCode) . '|' . $details->sourceProductKey] = $details;
    }

    public function findBySource(string $sourceSystemCode, string $sourceProductKey): ?LegacyProductMappingDetails
    {
        return $this->rows[strtolower($sourceSystemCode) . '|' . $sourceProductKey] ?? null;
    }

    public function upsert(array $data): LegacyProductMappingDetails
    {
        throw new \RuntimeException('Not used in unit tests');
    }
}
