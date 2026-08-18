<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Entity\ProductPrice;
use TriCab\Core\Product\Domain\Entity\ProductRelationship;
use TriCab\Core\Product\Domain\Entity\SpecificationDefinition;
use TriCab\Core\Product\Domain\Exception\InvalidProductLifecycleException;
use TriCab\Core\Product\Domain\Exception\InvalidProductRelationshipException;
use TriCab\Core\Product\Domain\Exception\InvalidProductStatusTransition;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\Exception\InvalidSpecificationValueException;
use TriCab\Core\Product\Domain\Service\ProductCodeSegmentAssembler;
use TriCab\Core\Product\Domain\Service\ProductPublishPolicy;
use TriCab\Core\Product\Domain\Service\ProductRelationshipPolicy;
use TriCab\Core\Product\Domain\Service\ProductStatusPolicy;
use TriCab\Core\Product\Domain\Service\SpecificationValueValidator;
use TriCab\Core\Product\Domain\ValueObject\AccessoryRequirementCode;
use TriCab\Core\Product\Domain\ValueObject\CurrencyCode;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\EffectiveDateRange;
use TriCab\Core\Product\Domain\ValueObject\Money;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\SpecificationDataType;
use TriCab\Core\Product\Domain\ValueObject\SpecificationScope;

final class ProductDomainServicesTest extends TestCase
{
    public function test_status_transition_draft_to_published_allowed(): void
    {
        $policy = new ProductStatusPolicy();
        self::assertTrue(
            $policy->isTransitionAllowed(ProductStatusCode::draft(), ProductStatusCode::published()),
        );
        $policy->assertTransitionAllowed(ProductStatusCode::draft(), ProductStatusCode::published());
    }

    public function test_status_transition_published_to_draft_rejected(): void
    {
        $policy = new ProductStatusPolicy();
        $this->expectException(InvalidProductStatusTransition::class);
        $policy->assertTransitionAllowed(ProductStatusCode::published(), ProductStatusCode::draft());
    }

    public function test_publish_policy_blocks_archived(): void
    {
        $product = new Product(
            id: null,
            publicId: ProductPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            productName: 'Cable',
            status: ProductStatusCode::archived(),
            lifecycleStatus: ProductLifecycleStatusCode::active(),
        );

        $this->expectException(InvalidProductLifecycleException::class);
        (new ProductPublishPolicy())->assertCanPublish($product);
    }

    public function test_publish_policy_blocks_soft_deleted(): void
    {
        $product = new Product(
            id: null,
            publicId: ProductPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            productName: 'Cable',
            status: ProductStatusCode::draft(),
            lifecycleStatus: ProductLifecycleStatusCode::active(),
            deletedAtUtc: new DateTimeImmutable('2026-08-05T00:00:00Z'),
            deletedByUserId: UserId::fromInt(1),
        );

        $this->expectException(InvalidProductLifecycleException::class);
        (new ProductPublishPolicy())->assertCanPublish($product);
    }

    public function test_relationship_rejects_self(): void
    {
        $policy = new ProductRelationshipPolicy();
        $a = ProductId::fromInt(1);

        $this->expectException(InvalidProductRelationshipException::class);
        $policy->assertCanAdd($a, $a, ProductRelationshipType::related(), []);
    }

    public function test_relationship_duplicate_detection(): void
    {
        $policy = new ProductRelationshipPolicy();
        $a = ProductId::fromInt(1);
        $b = ProductId::fromInt(2);
        $existing = [
            new ProductRelationship(
                $a,
                $b,
                ProductRelationshipType::related(),
                DisplayOrder::fromInt(0),
            ),
        ];

        $this->expectException(InvalidProductRelationshipException::class);
        $policy->assertCanAdd($a, $b, ProductRelationshipType::related(), $existing);
    }

    public function test_replacement_self_reference_on_entity(): void
    {
        $this->expectException(InvalidProductRelationshipException::class);
        new ProductRelationship(
            ProductId::fromInt(5),
            ProductId::fromInt(5),
            ProductRelationshipType::replacement(),
            DisplayOrder::fromInt(0),
        );
    }

    public function test_product_code_assembler(): void
    {
        $assembler = new ProductCodeSegmentAssembler();
        $code = $assembler->assemble([
            ['order' => 2, 'value' => '16', 'required' => true, 'separatorAfter' => ''],
            ['order' => 1, 'value' => 'TC', 'required' => true, 'separatorAfter' => '-'],
            ['order' => 3, 'value' => 'BK', 'required' => false, 'separatorAfter' => ''],
        ]);
        self::assertSame('TC-16BK', $code->toString());
        self::assertSame('TC + 16 + BK', $assembler->explain([
            ['order' => 1, 'value' => 'TC', 'required' => true],
            ['order' => 2, 'value' => '16', 'required' => true],
            ['order' => 3, 'value' => 'BK', 'required' => false],
        ]));
    }

    public function test_product_code_assembler_rejects_missing_required(): void
    {
        $this->expectException(InvalidProductValue::class);
        (new ProductCodeSegmentAssembler())->assemble([
            ['order' => 1, 'value' => '', 'required' => true],
        ]);
    }

    public function test_specification_integer_and_range_validation(): void
    {
        $definition = new SpecificationDefinition(
            id: null,
            definitionCode: 'cores',
            definitionName: 'Core count',
            dataType: SpecificationDataType::fromString('integer'),
            scope: SpecificationScope::fromString('both'),
            displayOrder: DisplayOrder::fromInt(1),
            minimumValue: '1',
            maximumValue: '48',
        );
        $validator = new SpecificationValueValidator();
        $validator->assertValid($definition, ['integer' => 4], 'product');

        $this->expectException(InvalidSpecificationValueException::class);
        $validator->assertValid($definition, ['integer' => 99], 'product');
    }

    public function test_specification_scope_product_only(): void
    {
        $definition = new SpecificationDefinition(
            id: null,
            definitionCode: 'family-note',
            definitionName: 'Family note',
            dataType: SpecificationDataType::fromString('text'),
            scope: SpecificationScope::fromString('product'),
            displayOrder: DisplayOrder::fromInt(1),
        );

        $this->expectException(InvalidSpecificationValueException::class);
        (new SpecificationValueValidator())->assertValid(
            $definition,
            ['text' => 'hello'],
            'variant',
        );
    }

    public function test_specification_single_select_option(): void
    {
        $definition = new SpecificationDefinition(
            id: null,
            definitionCode: 'colour',
            definitionName: 'Colour',
            dataType: SpecificationDataType::fromString('single-select'),
            scope: SpecificationScope::fromString('variant'),
            displayOrder: DisplayOrder::fromInt(1),
        );
        $validator = new SpecificationValueValidator();
        $validator->assertValid($definition, ['optionId' => 3], 'variant', [1, 2, 3]);

        $this->expectException(InvalidSpecificationValueException::class);
        $validator->assertValid($definition, ['optionId' => 9], 'variant', [1, 2, 3]);
    }

    public function test_pricing_rejects_non_positive_quantity(): void
    {
        $currency = CurrencyCode::fromString('AUD');
        $price = new ProductPrice(
            unitPrice: Money::of('10.00', $currency),
            currency: $currency,
            effectiveRange: EffectiveDateRange::of(null, null),
            minimumQuantity: '1',
            productId: ProductId::fromInt(1),
        );
        self::assertSame('10', $price->unitPrice()->amount());

        $this->expectException(InvalidProductValue::class);
        new ProductPrice(
            unitPrice: Money::of('10.00', $currency),
            currency: $currency,
            effectiveRange: EffectiveDateRange::of(null, null),
            minimumQuantity: '0',
            productId: ProductId::fromInt(1),
        );
    }

    public function test_accessory_requires_requirement_code(): void
    {
        $this->expectException(InvalidProductRelationshipException::class);
        new ProductRelationship(
            ProductId::fromInt(1),
            ProductId::fromInt(2),
            ProductRelationshipType::accessory(),
            DisplayOrder::fromInt(0),
            null,
        );
    }

    public function test_accessory_with_requirement_ok(): void
    {
        $rel = new ProductRelationship(
            ProductId::fromInt(1),
            ProductId::fromInt(2),
            ProductRelationshipType::accessory(),
            DisplayOrder::fromInt(0),
            AccessoryRequirementCode::fromString('required'),
        );
        self::assertSame('required', $rel->accessoryRequirement()?->toString());
    }
}
