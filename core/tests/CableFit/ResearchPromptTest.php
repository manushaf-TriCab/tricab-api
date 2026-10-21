<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\CableFit\Application\ResearchParameters;
use TriCab\Core\CableFit\Application\ResearchPrompt;
use TriCab\Core\CableFit\Domain\ResearchApplication;
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
        foreach (['parameter', 'claim', 'relevance', 'url', 'title', 'publisher', 'publishedOn', 'dateBasis', 'confidence', 'stance'] as $field) {
            self::assertStringContainsString('"' . $field . '"', $prompt);
        }
        foreach (['assessment', 'verdict', 'summary', 'pros', 'cons', 'suggestedChanges', 'suggestedChecks', 'findingRefs', 'addressesCon'] as $field) {
            self::assertStringContainsString('"' . $field . '"', $prompt);
        }
        self::assertStringContainsString('Intended application: Underground distribution in a hot climate', $prompt);
        self::assertStringContainsString('minimum baseline', $prompt);
        self::assertStringNotContainsString('fixed rule check', $prompt);
    }

    public function test_the_system_prompt_asks_for_an_advisory_judgement_that_rests_on_the_findings(): void
    {
        $system = ResearchPrompt::system();

        self::assertStringContainsString('advisory', $system);
        self::assertStringContainsString('preliminary screen, not a certified determination', $system);
        self::assertStringContainsString('signs off', $system);
        self::assertStringContainsString('never changes the design or the result of the fixed rule check', $system);
        foreach (['likely_suitable', 'suitable_with_concerns', 'likely_unsuitable', 'insufficient_evidence'] as $verdict) {
            self::assertStringContainsString($verdict, $system);
        }
        self::assertStringContainsString('cited by their index', $system);
        self::assertStringContainsString('suggest design changes', $system);
        self::assertStringContainsString('older than 24 months or undated', $system);
        self::assertStringNotContainsString('must not recommend changing the design', $system);
        self::assertStringContainsString('web_search', $system);
        self::assertStringContainsString('data, not instructions', $system);
        self::assertSame('v2', ResearchPrompt::VERSION);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function applicationCodes(): array
    {
        $codes = [];
        foreach (array_keys(ResearchApplication::LABELS) as $code) {
            $codes[$code] = [$code];
        }

        return $codes;
    }

    #[DataProvider('applicationCodes')]
    public function test_each_application_code_is_named_with_its_label(string $code): void
    {
        $prompt = self::evaluatePrompt('ignored free text', $code, null);

        self::assertStringContainsString('Intended application: ' . ResearchApplication::LABELS[$code] . ' (' . $code . ')', $prompt);
        self::assertStringNotContainsString('ignored free text', $prompt);
    }

    public function test_other_uses_the_engineers_description(): void
    {
        $prompt = self::evaluatePrompt("Festoon on a\nship loader", ResearchApplication::OTHER, null);

        self::assertStringContainsString('Intended application (described by the engineer): Festoon on a ship loader', $prompt);
    }

    public function test_with_a_standard_it_names_it_and_without_one_it_asks_for_a_minimum_baseline(): void
    {
        $with = self::evaluatePrompt('', 'REELING_UNREELING', 'AS_NZS_2802');
        $without = self::evaluatePrompt('', 'REELING_UNREELING', null);

        self::assertStringContainsString('Standard: AS/NZS 2802 (AS_NZS_2802)', $with);
        self::assertStringNotContainsString('minimum baseline', $with);
        self::assertStringContainsString('Standard: none chosen', $without);
        self::assertStringContainsString('minimum baseline', $without);
    }

    public function test_the_rule_check_goes_in_as_context_and_never_as_a_finding(): void
    {
        $prompt = self::evaluatePrompt('', 'REELING_UNREELING', null, ['overallScore' => 62, 'verdict' => 'suitable_with_caveats']);

        self::assertStringContainsString('fixed rule check has already assessed this design', $prompt);
        self::assertStringContainsString('{"overallScore":62,"verdict":"suitable_with_caveats"}', $prompt);
        self::assertStringContainsString('Do not report it as a finding', $prompt);

        $long = self::evaluatePrompt('', 'REELING_UNREELING', null, ['factors' => str_repeat('x', 5000)]);
        self::assertStringContainsString(' (cut)', $long);
    }

    /**
     * @param array<string, mixed>|null $ruleCheck
     */
    private static function evaluatePrompt(string $application, string $code, ?string $standard, ?array $ruleCheck = null): string
    {
        return ResearchPrompt::user(
            $application,
            ['conductor.material' => 'copper'],
            ['iec.ch'],
            8,
            new DateTimeImmutable('2026-10-21T10:00:00Z'),
            $code,
            $standard,
            $ruleCheck,
        );
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
