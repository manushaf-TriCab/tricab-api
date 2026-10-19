<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use PHPUnit\Framework\TestCase;
use TriCab\Core\CableFit\Application\CableFitHandoffService;
use TriCab\Core\Npr\NprRequestSnapshot;

final class CableFitHandoffServiceTest extends TestCase
{
    /**
     * @param array<string, mixed>       $answers
     * @param list<array<string, mixed>> $variants
     */
    private function snapshot(array $answers, int $rev = 1, array $variants = []): NprRequestSnapshot
    {
        return new NprRequestSnapshot('npr-1', 'NPR-0001', $rev, 'Title', 'Customer', $answers, $variants);
    }

    /** @return array<string, mixed> */
    private function richAnswers(): array
    {
        return [
            'voltage_rating' => ['state' => 'provided', 'value' => ['originalNotation' => '11kV', 'voltage_kV' => 11.0, 'currentType' => 'AC']],
            'main_core_count' => ['state' => 'provided', 'value' => 3],
            'additional_core_requirements' => ['state' => 'provided', 'value' => [
                ['type' => 'earth', 'count' => 3],
                ['type' => 'pilot', 'count' => 1],
            ]],
            'conductor_size' => ['state' => 'provided', 'value' => 95],
            'conductor_material' => ['state' => 'provided', 'value' => ['copper', 'aluminium']],
            'conductor_class' => ['state' => 'provided', 'value' => ['class_5']],
            'insulation_material' => ['state' => 'provided', 'value' => ['XLPE']],
            'screening_required' => ['state' => 'provided', 'value' => 'yes'],
            'screen_type' => ['state' => 'provided', 'value' => 'Copper tape screen'],
            'armour' => ['state' => 'provided', 'value' => ['SWA']],
            'sheath_material' => ['state' => 'provided', 'value' => ['PVC']],
            'sheath_colours' => ['state' => 'provided', 'value' => ['Orange']],
            'operating_temperature' => ['state' => 'provided', 'value' => ['originalText' => '-25C to +90C continuous']],
            'standards_compliance' => ['state' => 'provided', 'value' => 'AS/NZS 1429.1, AS/NZS 2802'],
        ];
    }

    public function testMapsSection5ToConstruction(): void
    {
        $r = (new CableFitHandoffService())->buildHandoff($this->snapshot($this->richAnswers()));
        $c = $r->construction;

        self::assertSame('1.0', $r->mappingVersion);
        self::assertSame('default', $r->variantId);
        self::assertSame(11.0, $c['systemVoltage_kV']);
        self::assertIsArray($c['layup']);
        self::assertSame(3, $c['layup']['numberOfCores']);
        self::assertSame(['earth', 'earth', 'earth', 'pilot'], $c['layup']['additionalCoreOrder']);
        self::assertIsArray($c['conductor']);
        self::assertSame(95.0, $c['conductor']['csa_mm2']);
        self::assertSame(5, $c['conductor']['conductorClass']);
        self::assertSame('ROPE_LAY', $c['conductor']['formation']);
        self::assertSame('copper', $c['conductor']['material']);
        self::assertIsArray($c['insulation']);
        self::assertSame('XLPE', $c['insulation']['material']);
        self::assertIsArray($c['metallicScreen']);
        self::assertSame('COPPER_TAPE', $c['metallicScreen']['screenType']);
        self::assertIsArray($c['armour']);
        self::assertSame('GALV_STEEL_WIRE', $c['armour']['armourType']);
        self::assertIsArray($c['sheath']);
        self::assertSame('PVC', $c['sheath']['material']);
        self::assertSame('Orange', $c['sheath']['color']);
    }

    public function testProvenanceDistinguishesSalesFromDefaults(): void
    {
        $r = (new CableFitHandoffService())->buildHandoff($this->snapshot([]));

        self::assertSame(['numberOfCores' => 'default'], $r->provenance['layup']);
        self::assertIsArray($r->provenance['conductor']);
        self::assertSame('default', $r->provenance['conductor']['csa_mm2']);
        self::assertSame('unconfigured', $r->provenance['conductor']['numberOfStrands']);
        self::assertIsArray($r->provenance['sheath']);
        self::assertSame('default', $r->provenance['sheath']['material']);

        $rich = (new CableFitHandoffService())->buildHandoff($this->snapshot($this->richAnswers()));
        self::assertIsArray($rich->provenance['conductor']);
        self::assertSame('sales_provided', $rich->provenance['conductor']['csa_mm2']);
        self::assertSame('sales_provided', $rich->provenance['systemVoltage_kV']);
    }

    public function testUnresolvedRequirements(): void
    {
        $r = (new CableFitHandoffService())->buildHandoff($this->snapshot($this->richAnswers()));
        $byField = [];
        foreach ($r->unresolved as $u) {
            $byField[is_string($u['field']) ? $u['field'] : ''] = $u;
        }
        self::assertSame('-25C to +90C continuous', $byField['operating_temperature']['value']);
        self::assertSame('AS/NZS 1429.1, AS/NZS 2802', $byField['standards_compliance']['value']);
        self::assertArrayNotHasKey('voltage_rating', $byField);

        $missing = (new CableFitHandoffService())->buildHandoff($this->snapshot([]));
        self::assertSame('voltage_rating', $missing->unresolved[0]['field']);
        self::assertSame('Not provided', $missing->unresolved[0]['value']);
    }

    public function testUnsupportedVoltageAndInsulationAreUnresolved(): void
    {
        $r = (new CableFitHandoffService())->buildHandoff($this->snapshot([
            'voltage_rating' => '15 kV',
            'insulation_material' => 'PVC',
        ]));
        $fields = array_column($r->unresolved, 'field');
        self::assertContains('voltage_rating', $fields);
        self::assertContains('insulation_material', $fields);
        self::assertArrayNotHasKey('systemVoltage_kV', $r->construction);
        self::assertArrayNotHasKey('insulation', $r->construction);
    }

    public function testAlternativesAreDetectedAndChoiceIsAppliedWithoutMutatingInput(): void
    {
        $svc = new CableFitHandoffService();
        $snap = $this->snapshot($this->richAnswers());

        $default = $svc->buildHandoff($snap);
        self::assertSame(['conductor_material' => 'copper'], $default->chosenAlternatives);
        self::assertArrayHasKey('conductor_material', $default->alternatives);

        $al = $svc->buildHandoff($snap, null, ['conductor_material' => 'aluminium']);
        self::assertIsArray($al->construction['conductor']);
        self::assertSame('aluminium', $al->construction['conductor']['material']);

        $invalid = $svc->buildHandoff($snap, null, ['conductor_material' => 'gold']);
        self::assertSame('copper', $invalid->chosenAlternatives['conductor_material']);
        self::assertSame($this->richAnswers(), $snap->section5Answers);
    }

    public function testVariantAnswersOverrideAndSelectionFallsBackToFirst(): void
    {
        $variants = [
            ['variantId' => 'v1', 'name' => 'A', 'answers' => ['conductor_size' => 50]],
            ['variantId' => 'v2', 'name' => 'B', 'answers' => ['conductor_size' => 120]],
        ];
        $snap = $this->snapshot($this->richAnswers(), 1, $variants);
        $svc = new CableFitHandoffService();

        $v2 = $svc->buildHandoff($snap, 'v2');
        self::assertSame('v2', $v2->variantId);
        self::assertIsArray($v2->construction['conductor']);
        self::assertSame(120.0, $v2->construction['conductor']['csa_mm2']);

        $unknown = $svc->buildHandoff($snap, 'nope');
        self::assertSame('v1', $unknown->variantId);
        self::assertIsArray($unknown->construction['conductor']);
        self::assertSame(50.0, $unknown->construction['conductor']['csa_mm2']);
    }

    public function testStaleness(): void
    {
        $svc = new CableFitHandoffService();
        self::assertFalse($svc->isStale(1, 1));
        self::assertTrue($svc->isStale(1, 2));
        self::assertFalse($svc->isStale(null, 5));
        self::assertStringContainsString('revision 1', $svc->staleReason(1, 2));
    }
}
