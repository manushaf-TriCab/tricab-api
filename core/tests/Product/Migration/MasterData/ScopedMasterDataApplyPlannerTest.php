<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\MasterData;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ScopedMasterDataApplyPlanner;

final class ScopedMasterDataApplyPlannerTest extends TestCase
{
    public function testRejectsUnapprovedScope(): void
    {
        $planner = new ScopedMasterDataApplyPlanner();
        $this->expectException(\InvalidArgumentException::class);
        $planner->normalizeScopes(['categories']);
    }

    public function testRequiresScope(): void
    {
        $planner = new ScopedMasterDataApplyPlanner();
        $this->expectException(\InvalidArgumentException::class);
        $planner->normalizeScopes([]);
    }

    public function testPlanProposesCreateAndInsertsWhenMissing(): void
    {
        $planner = new ScopedMasterDataApplyPlanner();
        $scopes = $planner->normalizeScopes([
            'specification-units',
            'accessories-family',
            'migration-spec-field-schema',
        ]);
        $plan = $planner->plan([
            'specificationUnitsPresent' => false,
            'units' => [],
            'families' => [['code' => 'CABLE', 'name' => 'Cables'], ['code' => 'PLUG', 'name' => 'Plugs']],
            'specificationFieldMappingsPresent' => true,
            'legacyStyleCodePresent' => false,
            'oldSourceUniquePresent' => true,
            'styleUniqueIndexPresent' => false,
        ], $scopes);

        self::assertSame(1, $plan['counts']['CREATE_TABLE']);
        self::assertSame(12, $plan['counts']['INSERT']); // 11 units + ACCESSORIES
        self::assertSame(1, $plan['counts']['ALTER_TABLE']);
        self::assertSame(1, $plan['counts']['DROP_CONSTRAINT']);
        self::assertSame(1, $plan['counts']['CREATE_INDEX']);
        self::assertSame(0, $plan['forbiddenGuarantees']['dvMappingRecordInserts']);
        self::assertSame(0, $plan['forbiddenGuarantees']['productWrites']);
    }

    public function testPlanSkipsWhenPresent(): void
    {
        $planner = new ScopedMasterDataApplyPlanner();
        $units = [];
        foreach ($planner->approvedUnits() as $u) {
            $units[] = ['code' => $u['code']];
        }
        $plan = $planner->plan([
            'specificationUnitsPresent' => true,
            'units' => $units,
            'families' => [['code' => 'ACCESSORIES', 'name' => 'Accessories']],
            'specificationFieldMappingsPresent' => true,
            'legacyStyleCodePresent' => true,
            'oldSourceUniquePresent' => false,
            'styleUniqueIndexPresent' => true,
        ], $planner->normalizeScopes(['specification-units', 'accessories-family', 'migration-spec-field-schema']));

        self::assertSame(0, $plan['counts']['CREATE_TABLE']);
        self::assertSame(0, $plan['counts']['INSERT']);
        self::assertGreaterThan(0, $plan['counts']['SKIP']);
    }
}
