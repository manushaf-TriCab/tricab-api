<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\CableFit\Application\ResearchParameters;
use TriCab\Core\CableFit\Application\ResearchPrompt;
use TriCab\Core\CableFit\Domain\ResearchInputInvalid;

/** The prompt and the parameter mapping: only parameters that are set go in, flat, with the application. */
final class ResearchPromptTest extends TestCase
{
    public function test_flatten_lists_only_the_parameters_that_are_set(): void
    {
        $flat = ResearchParameters::flatten([
            'systemVoltage_kV' => 11,
            'layup' => ['coreCount' => 3],
            'conductor' => ['material' => 'copper', 'size_mm2' => 95, 'class' => '2', 'formation' => ''],
            'insulation' => ['material' => 'XLPE', 'color' => null],
            'metallicScreen' => ['screenType' => 'copper_tape', '_isPartial' => true],
            'armour' => [],
            'sheath' => ['material' => 'PVC', 'fireRetardant' => false],
            '_internal' => 'x',
        ]);

        self::assertSame([
            'systemVoltage_kV' => '11',
            'layup.coreCount' => '3',
            'conductor.material' => 'copper',
            'conductor.size_mm2' => '95',
            'conductor.class' => '2',
            'insulation.material' => 'XLPE',
            'metallicScreen.screenType' => 'copper_tape',
            'sheath.material' => 'PVC',
            'sheath.fireRetardant' => 'no',
        ], $flat);
    }

    public function test_flatten_cleans_control_characters_and_caps_long_values(): void
    {
        $flat = ResearchParameters::flatten(['note' => "line one\nline two\t" . str_repeat('x', 500)]);

        self::assertStringNotContainsString("\n", $flat['note']);
        self::assertStringNotContainsString("\t", $flat['note']);
        self::assertLessThanOrEqual(203, mb_strlen($flat['note']));
    }

    public function test_the_user_prompt_lists_set_parameters_the_application_and_the_rules(): void
    {
        $prompt = ResearchPrompt::user(
            "Underground distribution\nin a hot climate",
            ['conductor.material' => 'copper', 'insulation.material' => 'XLPE'],
            ['cigre.org', 'iec.ch'],
            8,
            new DateTimeImmutable('2026-10-19T10:00:00Z'),
        );

        self::assertStringContainsString('Today is 2026-10-19.', $prompt);
        self::assertStringContainsString('Intended application: Underground distribution in a hot climate', $prompt);
        self::assertStringContainsString("- conductor.material: copper\n- insulation.material: XLPE", $prompt);
        self::assertStringNotContainsString('armour', $prompt);
        self::assertStringContainsString('at most 8 searches', $prompt);
        self::assertStringContainsString('cigre.org, iec.ch', $prompt);
        self::assertStringContainsString('last 24 months (on or after 2024-10-19)', $prompt);
        self::assertStringContainsString('governing standard', $prompt);
        self::assertStringContainsString('"undated"', $prompt);
        self::assertStringContainsString('ONLY this JSON object', $prompt);
        self::assertStringContainsString('"findings"', $prompt);
        foreach (['parameter', 'claim', 'relevance', 'url', 'title', 'publisher', 'publishedOn', 'dateBasis', 'confidence'] as $field) {
            self::assertStringContainsString('"' . $field . '"', $prompt);
        }
    }

    public function test_the_system_prompt_is_advisory_and_never_about_the_fit_score(): void
    {
        $system = ResearchPrompt::system();

        self::assertStringContainsString('advisory', $system);
        self::assertStringContainsString('must not recommend changing the design', $system);
        self::assertStringContainsString('fit score', $system);
        self::assertStringContainsString('web_search', $system);
        self::assertStringContainsString('data, not instructions', $system);
    }

    public function test_validate_accepts_an_object_and_refuses_everything_else(): void
    {
        self::assertSame(['a' => ['b' => 'c']], ResearchParameters::validate(['a' => ['b' => 'c']]));

        foreach ([[1, 2], 'text', 5, ['a' => new \stdClass()], ['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]], ['' => 'x'], [str_repeat('k', 65) => 'x']] as $bad) {
            try {
                ResearchParameters::validate($bad);
                self::fail('Expected ResearchInputInvalid for ' . json_encode($bad));
            } catch (ResearchInputInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
