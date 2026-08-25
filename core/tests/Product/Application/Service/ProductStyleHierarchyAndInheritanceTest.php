<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand;
use TriCab\Core\Product\Application\Command\MakeLocalCopyStyleResourceCommand;
use TriCab\Core\Product\Application\Command\ProductStyleStatusCommand;
use TriCab\Core\Product\Application\Command\UpdateStyleInheritanceCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\Exception\DuplicateProductStyleCode;
use TriCab\Core\Product\Application\Exception\InvalidProductStyleHierarchy;
use TriCab\Core\Product\Application\Exception\ProductStyleInUse;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleHierarchyService;
use TriCab\Core\Product\Application\Service\MakeLocalCopyStyleResourceService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Application\Service\ResolveEffectiveStyleResourcesService;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\StyleInheritanceMode;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class ProductStyleHierarchyAndInheritanceTest extends TestCase
{
    private InMemoryProductStyleRepository $styles;
    private InMemoryProductStore $products;
    private ManageProductStyleService $manage;
    private ResolveEffectiveStyleResourcesService $resolver;
    private MakeLocalCopyStyleResourceService $makeLocalCopy;
    private DiscoverLegacyStyleHierarchyService $discovery;
    private ActorContext $actor;
    private UserId $user;

    protected function setUp(): void
    {
        $this->styles = new InMemoryProductStyleRepository();
        $this->products = new InMemoryProductStore();
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $this->user = UserId::fromInt(1);
        $audit = new ProductAuditRecorder($this->products->audit());
        $tx = new PassthroughApplicationTransaction();
        $this->manage = new ManageProductStyleService($this->styles, $this->products->specifications(), $tx, $audit);
        $this->resolver = new ResolveEffectiveStyleResourcesService($this->styles, $this->products->specifications());
        $this->makeLocalCopy = new MakeLocalCopyStyleResourceService($this->styles, $this->resolver, $tx, $audit);
        $this->discovery = new DiscoverLegacyStyleHierarchyService($this->styles);
    }

    public function test_create_root_child_and_nested_child(): void
    {
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV Base'));
        $bva = $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BV Armoured', $bv->publicId));
        $nested = $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA-1', 'Nested', $bva->publicId));

        self::assertNull($bv->parentPublicId);
        self::assertSame('LOCAL', $bv->inheritance->specifications);
        self::assertSame($bv->publicId, $bva->parentPublicId);
        self::assertSame('INHERIT', $bva->inheritance->specifications);
        self::assertSame($bva->publicId, $nested->parentPublicId);
        self::assertCount(1, $this->manage->tree());
        self::assertSame('BVA', $this->manage->tree()[0]->children[0]->styleCode);
    }

    public function test_duplicate_style_code_rejected(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $this->expectException(DuplicateProductStyleCode::class);
        $this->manage->create($this->actor, new UpsertProductStyleCommand('bv', 'Other'));
    }

    public function test_self_parent_rejected(): void
    {
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $this->expectException(InvalidProductStyleHierarchy::class);
        $this->manage->changeParent($this->actor, new ChangeProductStyleParentCommand($bv->publicId, $bv->publicId, $bv->rowVersion));
    }

    public function test_direct_and_deep_cycle_rejected(): void
    {
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $bva = $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA', $bv->publicId));
        $bva1 = $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA1', 'BVA1', $bva->publicId));

        $this->expectException(InvalidProductStyleHierarchy::class);
        $this->manage->changeParent($this->actor, new ChangeProductStyleParentCommand(
            $bv->publicId,
            $bva1->publicId,
            $this->manage->get($bv->publicId)->rowVersion,
        ));
    }

    public function test_archive_blocked_when_children_exist(): void
    {
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA', $bv->publicId));
        $this->expectException(ProductStyleInUse::class);
        $this->manage->archive($this->actor, new ProductStyleStatusCommand($bv->publicId, $this->manage->get($bv->publicId)->rowVersion));
    }

    public function test_bv_family_effective_specifications_with_overrides(): void
    {
        $family = $this->seedBvFamily();
        $voltage = $this->seedDefinition('VOLTAGE', 'Voltage');
        $temp = $this->seedDefinition('TEMPERATURE', 'Temperature');
        $insulation = $this->seedDefinition('INSULATION', 'Insulation');

        $this->styles->replaceStyleSpecificationValues($family['BV']->publicId, [
            $this->textValue($voltage, '0.6/1 kV'),
            $this->textValue($temp, '90°C'),
            $this->textValue($insulation, 'PVC'),
        ], $this->user);
        $this->styles->replaceStyleSpecificationValues($family['BVA']->publicId, [
            $this->textValue($temp, '105°C'),
        ], $this->user);
        $this->manage->updateInheritance($this->actor, new UpdateStyleInheritanceCommand(
            $family['BVA']->publicId,
            [StyleResourceType::SPECIFICATIONS => StyleInheritanceMode::INHERIT_AND_EXTEND],
            $this->manage->get($family['BVA']->publicId)->rowVersion,
        ));

        $effective = $this->indexByCode($this->resolver->specificationsForStyle($family['BVA']->publicId));
        self::assertSame('0.6/1 kV', $effective['VOLTAGE']->displayValue);
        self::assertTrue($effective['VOLTAGE']->provenance->inherited);
        self::assertSame('BV', $effective['VOLTAGE']->provenance->sourceStyleCode);
        self::assertSame('105°C', $effective['TEMPERATURE']->displayValue);
        self::assertFalse($effective['TEMPERATURE']->provenance->inherited);
        self::assertSame('BVA', $effective['TEMPERATURE']->provenance->sourceStyleCode);
        self::assertSame('PVC', $effective['INSULATION']->displayValue);

        $this->styles->replaceStyleSpecificationValues($family['BV']->publicId, [
            $this->textValue($voltage, '1.8/3 kV'),
            $this->textValue($temp, '90°C'),
            $this->textValue($insulation, 'PVC'),
        ], $this->user);
        $afterParentChange = $this->indexByCode($this->resolver->specificationsForStyle($family['BVA']->publicId));
        self::assertSame('1.8/3 kV', $afterParentChange['VOLTAGE']->displayValue);
        self::assertSame('105°C', $afterParentChange['TEMPERATURE']->displayValue);
    }

    public function test_product_and_variant_overrides_win(): void
    {
        $family = $this->seedBvFamily();
        $voltage = $this->seedDefinition('VOLTAGE', 'Voltage');
        $this->styles->replaceStyleSpecificationValues($family['BV']->publicId, [
            $this->textValue($voltage, '0.6/1 kV'),
        ], $this->user);
        $this->styles->assignProduct(
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            $family['BVA']->publicId,
            $this->user,
        );
        $this->products->specifications()->replaceProductValues(
            ProductPublicId::fromString('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
            [$this->textValue($voltage, '3.3 kV')],
            $this->user,
        );
        $this->products->specifications()->replaceVariantValues(
            ProductVariantPublicId::fromString('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'),
            [$this->textValue($voltage, '6.6 kV')],
            $this->user,
        );

        $productLevel = $this->indexByCode($this->resolver->specificationsForProduct('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'));
        self::assertSame('3.3 kV', $productLevel['VOLTAGE']->displayValue);
        self::assertSame('PRODUCT', $productLevel['VOLTAGE']->provenance->sourceType);

        $variantLevel = $this->indexByCode($this->resolver->specificationsForProduct(
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        ));
        self::assertSame('6.6 kV', $variantLevel['VOLTAGE']->displayValue);
        self::assertSame('VARIANT', $variantLevel['VOLTAGE']->provenance->sourceType);
    }

    public function test_local_mode_ignores_parent(): void
    {
        $family = $this->seedBvFamily();
        $voltage = $this->seedDefinition('VOLTAGE', 'Voltage');
        $this->styles->replaceStyleSpecificationValues($family['BV']->publicId, [
            $this->textValue($voltage, '0.6/1 kV'),
        ], $this->user);
        $this->styles->replaceStyleSpecificationValues($family['BVA']->publicId, [
            $this->textValue($voltage, 'child-only'),
        ], $this->user);
        $this->manage->updateInheritance($this->actor, new UpdateStyleInheritanceCommand(
            $family['BVA']->publicId,
            [StyleResourceType::SPECIFICATIONS => StyleInheritanceMode::LOCAL],
            $this->manage->get($family['BVA']->publicId)->rowVersion,
        ));
        $effective = $this->indexByCode($this->resolver->specificationsForStyle($family['BVA']->publicId));
        self::assertSame('child-only', $effective['VOLTAGE']->displayValue);
        self::assertFalse($effective['VOLTAGE']->provenance->inherited);
    }

    public function test_inherit_mode_ignores_local_style_rows(): void
    {
        $family = $this->seedBvFamily();
        $voltage = $this->seedDefinition('VOLTAGE', 'Voltage');
        $this->styles->replaceStyleSpecificationValues($family['BV']->publicId, [
            $this->textValue($voltage, '0.6/1 kV'),
        ], $this->user);
        $this->styles->replaceStyleSpecificationValues($family['BVA']->publicId, [
            $this->textValue($voltage, 'should-not-apply'),
        ], $this->user);
        $effective = $this->indexByCode($this->resolver->specificationsForStyle($family['BVA']->publicId));
        self::assertSame('0.6/1 kV', $effective['VOLTAGE']->displayValue);
        self::assertSame('BV', $effective['VOLTAGE']->provenance->sourceStyleCode);
    }

    public function test_media_inherit_and_extend_hero_override(): void
    {
        $family = $this->seedBvFamily();
        $this->styles->replaceStyleAssets($family['BV']->publicId, StyleResourceType::images(), [
            ['title' => 'hero.jpg', 'roleCode' => 'hero', 'storagePath' => 'bv/hero.jpg', 'displayOrder' => 1, 'isPrimary' => true],
            ['title' => 'cross.jpg', 'roleCode' => 'cross-section', 'storagePath' => 'bv/cross.jpg', 'displayOrder' => 2, 'isPrimary' => false],
        ], $this->user);
        $this->manage->updateInheritance($this->actor, new UpdateStyleInheritanceCommand(
            $family['BVM']->publicId,
            [StyleResourceType::IMAGES => StyleInheritanceMode::INHERIT_AND_EXTEND],
            $this->manage->get($family['BVM']->publicId)->rowVersion,
        ));
        $this->styles->replaceStyleAssets($family['BVM']->publicId, StyleResourceType::images(), [
            ['title' => 'bvm-hero.jpg', 'roleCode' => 'hero', 'storagePath' => 'bvm/hero.jpg', 'displayOrder' => 1, 'isPrimary' => true],
        ], $this->user);

        $assets = $this->resolver->assetsForStyle($family['BVM']->publicId, StyleResourceType::images());
        $byRole = [];
        foreach ($assets as $asset) {
            $byRole[(string) $asset->roleCode] = $asset;
        }
        self::assertSame('bvm/hero.jpg', $byRole['hero']->storagePath);
        self::assertFalse($byRole['hero']->provenance->inherited);
        self::assertSame('bv/cross.jpg', $byRole['cross-section']->storagePath);
        self::assertTrue($byRole['cross-section']->provenance->inherited);
    }

    public function test_make_local_copy_images_reuses_storage_path(): void
    {
        $family = $this->seedBvFamily();
        $this->styles->replaceStyleAssets($family['BV']->publicId, StyleResourceType::images(), [
            ['title' => 'hero-bv.jpg', 'roleCode' => 'hero', 'storagePath' => 'products/styles/bv/images/h1/hero-bv.jpg', 'storageContainer' => 'products', 'displayOrder' => 1, 'isPrimary' => true],
        ], $this->user);
        $bva = $this->manage->get($family['BVA']->publicId);
        $copied = $this->makeLocalCopy->execute($this->actor, new MakeLocalCopyStyleResourceCommand(
            $bva->publicId,
            StyleResourceType::IMAGES,
            $bva->rowVersion,
            true,
        ));
        self::assertSame(StyleInheritanceMode::LOCAL, $copied->inheritance->images);
        $local = $this->styles->listStyleAssets($copied->publicId, StyleResourceType::images());
        self::assertSame('products/styles/bv/images/h1/hero-bv.jpg', $local[0]['storagePath']);
        self::assertSame(
            2,
            $this->styles->countActiveStoragePathReferences('products/styles/bv/images/h1/hero-bv.jpg', 'products'),
        );
        $this->styles->softDeleteStyleAsset($copied->publicId, (string) $local[0]['publicId'], $this->user);
        self::assertSame(
            1,
            $this->styles->countActiveStoragePathReferences('products/styles/bv/images/h1/hero-bv.jpg', 'products'),
        );
    }

    public function test_make_local_copy_requires_confirmation_and_snapshots(): void
    {
        $family = $this->seedBvFamily();
        $voltage = $this->seedDefinition('VOLTAGE', 'Voltage');
        $this->styles->replaceStyleSpecificationValues($family['BV']->publicId, [
            $this->textValue($voltage, '0.6/1 kV'),
        ], $this->user);
        $bva = $this->manage->get($family['BVA']->publicId);
        try {
            $this->makeLocalCopy->execute($this->actor, new MakeLocalCopyStyleResourceCommand(
                $bva->publicId,
                StyleResourceType::SPECIFICATIONS,
                $bva->rowVersion,
                false,
            ));
            self::fail('Expected confirmation error');
        } catch (InvalidProductValue) {
            self::assertTrue(true);
        }

        $copied = $this->makeLocalCopy->execute($this->actor, new MakeLocalCopyStyleResourceCommand(
            $bva->publicId,
            StyleResourceType::SPECIFICATIONS,
            $bva->rowVersion,
            true,
        ));
        self::assertSame(StyleInheritanceMode::LOCAL, $copied->inheritance->specifications);
        $local = $this->styles->listStyleSpecificationValues($copied->publicId);
        self::assertSame('0.6/1 kV', $local[0]['valueText']);
        $this->styles->replaceStyleSpecificationValues($family['BV']->publicId, [
            $this->textValue($voltage, 'changed-parent'),
        ], $this->user);
        $after = $this->indexByCode($this->resolver->specificationsForStyle($copied->publicId));
        self::assertSame('0.6/1 kV', $after['VOLTAGE']->displayValue);
    }

    public function test_discovery_is_dry_run_and_does_not_persist(): void
    {
        $this->seedBvFamily();
        $preview = $this->discovery->preview();
        self::assertSame(0, $preview['writeGuard']['wordpressWrites']);
        self::assertSame(0, $preview['writeGuard']['tcWrites']);
        self::assertFalse($preview['writeGuard']['persisted']);
        $parents = array_map(static fn ($p) => $p->parentStyleCode . '>' . $p->childStyleCode, $preview['proposals']);
        self::assertContains('BV>BVA', $parents);
        self::assertContains('BV>BVC', $parents);
        $bva = $this->manage->get($this->styles->findByStyleCode('BVA')->publicId);
        self::assertSame('BV', $bva->parentStyleCode);
    }

    /**
     * @return array<string, \TriCab\Core\Product\Application\DTO\ProductStyleRecord>
     */
    private function seedBvFamily(): array
    {
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $out = ['BV' => $bv];
        foreach (['BVA', 'BVC', 'BVM', 'BVP'] as $code) {
            $out[$code] = $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code, $bv->publicId));
        }

        return $out;
    }

    private function seedDefinition(string $code, string $name): string
    {
        $created = $this->products->specifications()->createDefinition([
            'code' => $code,
            'name' => $name,
            'dataTypeCode' => 'text',
            'scopeCode' => 'product',
            'displayOrder' => 10,
            'isRequired' => false,
        ], $this->user);

        return $created->publicId;
    }

    /**
     * @return array<string, mixed>
     */
    private function textValue(string $definitionPublicId, string $text): array
    {
        return [
            'definitionPublicId' => $definitionPublicId,
            'valueText' => $text,
            'valueLongText' => null,
            'valueInteger' => null,
            'valueDecimal' => null,
            'valueBoolean' => null,
            'valueDate' => null,
            'valueUrl' => null,
            'valueMinDecimal' => null,
            'valueMaxDecimal' => null,
            'optionPublicId' => null,
            'optionPublicIds' => [],
        ];
    }

    /**
     * @param list<\TriCab\Core\Product\Application\DTO\EffectiveSpecificationValue> $items
     * @return array<string, \TriCab\Core\Product\Application\DTO\EffectiveSpecificationValue>
     */
    private function indexByCode(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[strtoupper($item->definitionCode)] = $item;
        }

        return $out;
    }
}
