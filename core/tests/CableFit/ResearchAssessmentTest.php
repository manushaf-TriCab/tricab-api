<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use PHPUnit\Framework\TestCase;
use TriCab\Core\CableFit\Domain\ResearchAssessment;

/** The parser rules for the agent's assessment (C4.4): cited evidence only, and a verdict it can support. */
final class ResearchAssessmentTest extends TestCase
{
    /** The model's findings 0, 1 and 3 were kept; 2 was dropped (off-list domain). */
    private const KEPT = [0 => 'f-a', 1 => 'f-b', 3 => 'f-d'];

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function raw(array $overrides = []): array
    {
        return $overrides + [
            'verdict' => 'suitable_with_concerns',
            'summary' => 'Fits the drag chain duty, but the PVC sheath is a risk at the stated cycle count.',
            'pros' => [
                ['point' => 'Class 6 conductor suits continuous flexing', 'parameter' => 'conductor.class', 'reasoning' => 'IEC 60228 class 6 is the finest stranding.', 'findingRefs' => [0]],
            ],
            'cons' => [
                ['point' => 'Off-list only', 'parameter' => 'general', 'reasoning' => 'x', 'findingRefs' => [2]],
                ['point' => 'PVC sheath abrades in chains', 'parameter' => 'sheath.material', 'reasoning' => 'Manufacturer data shows PUR lasts longer.', 'findingRefs' => [1, 2, 1]],
            ],
            'suggestedChanges' => [
                ['change' => 'Use a PUR sheath', 'parameter' => 'sheath.material', 'addressesCon' => 1, 'reasoning' => 'Better abrasion.', 'findingRefs' => [1]],
                ['change' => 'Cites only dropped', 'parameter' => 'general', 'addressesCon' => 1, 'reasoning' => 'x', 'findingRefs' => [2]],
                ['change' => 'Addresses the dropped con', 'parameter' => 'general', 'addressesCon' => 0, 'reasoning' => 'y', 'findingRefs' => ['3']],
            ],
            'suggestedChecks' => [
                ['check' => 'Run a 1 million cycle chain test', 'reason' => 'Confirms sheath life.'],
                ['reason' => 'no check text'],
            ],
        ];
    }

    public function test_a_valid_assessment_keeps_its_points_with_the_cited_finding_ids(): void
    {
        $a = ResearchAssessment::fromModel(self::raw(), self::KEPT);

        self::assertSame('suitable_with_concerns', $a->verdict);
        self::assertStringStartsWith('Fits the drag chain duty', $a->summary);
        self::assertSame(['f-a'], $a->pros[0]['findingIds']);
        self::assertSame('conductor.class', $a->pros[0]['parameter']);
        self::assertSame(['Run a 1 million cycle chain test'], array_column($a->suggestedChecks, 'check'));
    }

    public function test_a_con_or_change_citing_only_dropped_findings_is_removed_and_refs_are_deduplicated(): void
    {
        $a = ResearchAssessment::fromModel(self::raw(), self::KEPT);

        self::assertSame(['PVC sheath abrades in chains'], array_column($a->cons, 'point'));
        self::assertSame(['f-b'], $a->cons[0]['findingIds']);
        self::assertSame(['Use a PUR sheath', 'Addresses the dropped con'], array_column($a->suggestedChanges, 'change'));
        self::assertSame(0, $a->suggestedChanges[0]['addressesCon'], 'model con 1 is now con 0');
        self::assertNull($a->suggestedChanges[1]['addressesCon'], 'its con was removed');
        self::assertSame(['f-d'], $a->suggestedChanges[1]['findingIds']);
    }

    public function test_with_no_cited_pro_or_con_left_the_verdict_is_insufficient_evidence(): void
    {
        $a = ResearchAssessment::fromModel(self::raw(['verdict' => 'likely_suitable', 'pros' => [['point' => 'p', 'findingRefs' => [2, 9]]], 'cons' => []]), self::KEPT);

        self::assertSame('insufficient_evidence', $a->verdict);
        self::assertSame([], $a->pros);
    }

    public function test_an_unknown_verdict_becomes_insufficient_evidence(): void
    {
        foreach (['great', '', null, 3] as $bad) {
            self::assertSame('insufficient_evidence', ResearchAssessment::fromModel(self::raw(['verdict' => $bad]), self::KEPT)->verdict);
        }
        self::assertSame('likely_unsuitable', ResearchAssessment::fromModel(self::raw(['verdict' => ' Likely_Unsuitable ']), self::KEPT)->verdict);
    }

    public function test_no_assessment_at_all_is_insufficient_evidence_and_says_so(): void
    {
        foreach ([null, 'text', 5] as $raw) {
            $a = ResearchAssessment::fromModel($raw, self::KEPT);
            self::assertSame('insufficient_evidence', $a->verdict);
            self::assertSame('The agent gave no assessment.', $a->summary);
            self::assertSame([], $a->pros);
        }
    }

    public function test_it_round_trips_through_the_stored_json(): void
    {
        $a = ResearchAssessment::fromModel(self::raw(), self::KEPT);
        $json = json_decode(json_encode($a->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        $back = ResearchAssessment::fromArray($json);

        self::assertNotNull($back);
        self::assertSame($a->toArray(), $back->toArray());
        self::assertNull(ResearchAssessment::fromArray(['verdict' => 'nope']));
    }
}
