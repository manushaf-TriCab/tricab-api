<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ImportSpecificationDefinitionsCommand;
use TriCab\Core\Product\Application\Command\ReorderSpecificationCommand;
use TriCab\Core\Product\Application\Command\SpecificationStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationDefinitionCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationGroupCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationOptionCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationUnitCommand;
use TriCab\Core\Product\Application\DTO\SpecificationValidationRules;
use TriCab\Core\Product\Application\Exception\DuplicateSpecificationCode;
use TriCab\Core\Product\Application\Exception\SpecificationMasterConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\SpecificationMasterInUse;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Application\Service\SpecificationDefinitionService;
use TriCab\Core\Product\Application\Service\SpecificationGroupService;
use TriCab\Core\Product\Application\Service\SpecificationOptionService;
use TriCab\Core\Product\Application\Service\SpecificationUnitService;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class SpecificationMasterServicesTest extends TestCase
{
    private InMemoryProductStore $store;
    private SpecificationGroupService $groups;
    private SpecificationDefinitionService $definitions;
    private SpecificationOptionService $options;
    private SpecificationUnitService $units;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryProductStore();
        $tx = new PassthroughApplicationTransaction();
        $audit = new ProductAuditRecorder($this->store->audit());
        $repo = $this->store->specifications();
        $this->groups = new SpecificationGroupService($repo, $tx, $audit);
        $this->definitions = new SpecificationDefinitionService($repo, $tx, $audit);
        $this->options = new SpecificationOptionService($repo, $tx, $audit);
        $this->units = new SpecificationUnitService($repo, $tx, $audit);
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
    }

    public function test_group_create_duplicate_and_reorder(): void
    {
        $a = $this->groups->create($this->actor, new UpsertSpecificationGroupCommand('Electrical', 'electrical', displayOrder: 10));
        self::assertSame('ELECTRICAL', $a->code);
        $b = $this->groups->create($this->actor, new UpsertSpecificationGroupCommand('Mechanical', 'mechanical', displayOrder: 20));
        $this->groups->reorder($this->actor, new ReorderSpecificationCommand([$b->publicId, $a->publicId]));
        $list = $this->groups->list();
        self::assertSame('MECHANICAL', $list[0]->code);

        $this->expectException(DuplicateSpecificationCode::class);
        $this->groups->create($this->actor, new UpsertSpecificationGroupCommand('Other', 'ELECTRICAL'));
    }

    public function test_definition_concurrency_and_archive_blocked_by_values(): void
    {
        $group = $this->groups->create($this->actor, new UpsertSpecificationGroupCommand('Electrical', 'ELEC'));
        $def = $this->definitions->create($this->actor, new UpsertSpecificationDefinitionCommand(
            name: 'Voltage',
            code: 'VOLTAGE',
            dataTypeCode: 'measurement',
            scopeCode: 'product',
            groupPublicId: $group->publicId,
            unit: 'V',
            validationRules: new SpecificationValidationRules(helpText: 'Nominal voltage'),
        ));
        self::assertSame('V', $def->unit);
        self::assertSame('Nominal voltage', $def->validationRules->helpText);

        try {
            $this->definitions->update($this->actor, new UpsertSpecificationDefinitionCommand(
                name: 'Voltage',
                code: 'VOLTAGE',
                dataTypeCode: 'measurement',
                expectedRowVersion: 'stale',
                definitionPublicId: $def->publicId,
            ));
            self::fail('Expected concurrency conflict');
        } catch (SpecificationMasterConcurrencyConflict) {
            // expected
        }

        $this->store->specifications()->replaceProductValues(
            ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'),
            [['definitionPublicId' => $def->publicId, 'valueDecimal' => '0.6', 'valueText' => null, 'valueLongText' => null, 'valueInteger' => null, 'valueBoolean' => null, 'valueDate' => null, 'valueUrl' => null, 'valueMinDecimal' => null, 'valueMaxDecimal' => null, 'optionPublicId' => null, 'optionPublicIds' => []]],
            UserId::fromInt(1),
        );

        $this->expectException(SpecificationMasterInUse::class);
        $this->definitions->archive($this->actor, new SpecificationStatusCommand($def->publicId, $def->rowVersion));
    }

    public function test_options_for_select_and_unit_usage(): void
    {
        $unit = $this->units->create($this->actor, new UpsertSpecificationUnitCommand('Volt', 'V', 'V'));
        $def = $this->definitions->create($this->actor, new UpsertSpecificationDefinitionCommand(
            name: 'Conductor Material',
            code: 'CONDUCTOR',
            dataTypeCode: 'single-select',
            scopeCode: 'both',
            unitPublicId: $unit->publicId,
        ));
        self::assertSame('V', $def->unit);
        $opt = $this->options->create($this->actor, new UpsertSpecificationOptionCommand(
            definitionPublicId: $def->publicId,
            name: 'Copper',
            code: 'CU',
        ));
        self::assertSame('CU', $opt->code);

        $usage = $this->units->usage($unit->publicId);
        self::assertSame(1, $usage->definitionCount);
        $this->expectException(SpecificationMasterInUse::class);
        $this->units->archive($this->actor, new SpecificationStatusCommand($unit->publicId, $unit->rowVersion));
    }

    public function test_import_export_summary(): void
    {
        $this->groups->create($this->actor, new UpsertSpecificationGroupCommand('Electrical', 'ELECTRICAL'));
        $result = $this->definitions->import($this->actor, new ImportSpecificationDefinitionsCommand([
            [
                'code' => 'VOLTAGE',
                'name' => 'Voltage',
                'groupCode' => 'ELECTRICAL',
                'dataTypeCode' => 'measurement',
                'scopeCode' => 'product',
                'unit' => 'V',
                'validationRules' => ['helpText' => 'Help'],
            ],
            [
                'code' => 'VOLTAGE',
                'name' => 'Voltage Updated',
                'groupCode' => 'ELECTRICAL',
                'dataTypeCode' => 'measurement',
                'scopeCode' => 'product',
                'unit' => 'kV',
            ],
            [
                'code' => 'BAD',
                'name' => 'Bad',
                'dataTypeCode' => 'not-a-type',
            ],
        ]));
        self::assertSame(1, $result->created);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->skipped);
        self::assertNotEmpty($result->errors);

        $exported = $this->definitions->export();
        self::assertCount(1, $exported);
        self::assertSame('VOLTAGE', $exported[0]['code']);
        self::assertSame('kV', $exported[0]['unit']);
    }
}
