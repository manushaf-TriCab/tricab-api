<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;

/** Ported from the portal's test_backend.php sections 5 and 7, and docs/ANSWER_RULES.md. */
final class NprValidationServiceTest extends TestCase
{
    public function test_the_dictionary_loads_and_finds_a_field(): void
    {
        $field = NprFixtures::validator()->getFieldDefinition('customer_need');

        self::assertNotNull($field);
        self::assertNull(NprFixtures::validator()->getFieldDefinition('no_such_field'));
    }

    public function test_an_incomplete_draft_fails_submission_validation(): void
    {
        $result = NprFixtures::validator()->validateSubmission([
            'job_title' => ['state' => 'provided', 'value' => 'Mining Trailing Cable 33kV'],
            'customer_name' => ['state' => 'provided', 'value' => 'BHP'],
        ], []);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('variants', $result['errors']);
        self::assertArrayHasKey('sales_declaration', $result['errors']);
    }

    public function test_a_fully_answered_request_is_valid(): void
    {
        $result = NprFixtures::validator()->validateSubmission(NprFixtures::validRequestAnswers(), NprFixtures::validVariants());

        self::assertSame([], $result['errors']);
        self::assertTrue($result['valid']);
    }

    public function test_every_applicable_sales_field_needs_an_explicit_answer(): void
    {
        $answers = NprFixtures::validRequestAnswers();
        unset($answers['customer_need']);
        $variants = NprFixtures::validVariants();
        $variantAnswers = NprFixtures::validVariantAnswers();
        unset($variantAnswers['conductor_size']);
        $variants[0]['answers'] = $variantAnswers;

        $result = NprFixtures::validator()->validateSubmission($answers, $variants);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('requires an explicit answer', $result['errors']['customer_need'][0]);
        self::assertStringContainsString("Variant 'Standard 3x70mm2'", $result['errors']['var_1.conductor_size'][0]);
    }

    public function test_unknown_and_not_applicable_answers_need_a_reason(): void
    {
        $field = NprFixtures::validator()->getFieldDefinition('competitor_price');
        self::assertNotNull($field);

        self::assertSame(
            ["A reason is required when answering 'not_stated'"],
            NprFixtures::validator()->validateAnswerEnvelope($field, ['state' => 'not_stated', 'reason' => '  ', 'basis' => 'x', 'confidence' => 'estimate']),
        );
    }

    public function test_a_state_the_field_does_not_allow_is_refused(): void
    {
        $field = NprFixtures::validator()->getFieldDefinition('sales_declaration');
        self::assertNotNull($field);

        $errors = NprFixtures::validator()->validateAnswerEnvelope($field, ['state' => 'not_stated', 'reason' => 'x']);

        self::assertCount(1, $errors);
        self::assertStringContainsString("is not allowed for field 'sales_declaration'", $errors[0]);
    }

    public function test_commercial_answers_need_basis_and_confidence(): void
    {
        $field = NprFixtures::validator()->getFieldDefinition('volume_12_months');
        self::assertNotNull($field);

        $errors = NprFixtures::validator()->validateAnswerEnvelope($field, ['state' => 'provided', 'value' => 5000]);

        self::assertContains('Basis is required for commercial fields', $errors);
        self::assertContains("Confidence must be 'confirmed', 'estimate', or 'indicative'", $errors);
    }

    public function test_a_multi_select_needs_valid_options_and_a_selection_mode(): void
    {
        $field = NprFixtures::validator()->getFieldDefinition('sheath_material');
        self::assertNotNull($field);

        $errors = NprFixtures::validator()->validateAnswerEnvelope($field, ['state' => 'provided', 'value' => ['rubber'], 'selectionMode' => 'nonsense']);

        self::assertCount(2, $errors);
        self::assertSame('Invalid option(s): rubber', $errors[0]);
    }

    public function test_a_duration_needs_a_nonnegative_amount_and_a_known_unit(): void
    {
        $field = NprFixtures::validator()->getFieldDefinition('development_duration');
        self::assertNotNull($field);
        $check = static fn (mixed $value): array => NprFixtures::validator()->validateAnswerEnvelope($field, ['state' => 'provided', 'value' => $value]);

        self::assertSame([], $check(['amount' => 12, 'unit' => 'months']));
        self::assertSame(['Duration must be a nonnegative number'], $check(['amount' => -1, 'unit' => 'weeks']));
        self::assertSame(['Duration must be a nonnegative number'], $check(['unit' => 'weeks']));
        self::assertSame(["Duration unit must be 'days', 'weeks', or 'months'"], $check(['amount' => 3, 'unit' => 'years']));
        self::assertCount(2, $check('six months'));
    }

    public function test_none_armour_cannot_coexist_with_other_armour(): void
    {
        $variants = NprFixtures::validVariants();
        $variants[0]['answers'] = [...NprFixtures::validVariantAnswers(), 'armour' => ['state' => 'provided', 'value' => ['none', 'SWA'], 'selectionMode' => 'all_required']];

        $result = NprFixtures::validator()->validateSubmission(NprFixtures::validRequestAnswers(), $variants);

        self::assertContains("'None' cannot coexist with other armour selections", $result['errors']['var_1.armour']);
    }

    public function test_verbal_only_cannot_coexist_with_other_evidence(): void
    {
        $answers = NprFixtures::validRequestAnswers();
        $answers['document_types'] = ['state' => 'provided', 'value' => ['verbal_only', 'drawing'], 'selectionMode' => 'all_required'];

        $result = NprFixtures::validator()->validateSubmission($answers, NprFixtures::validVariants());

        self::assertContains("'Verbal only' cannot coexist with other document evidence choices", $result['errors']['document_types']);
    }

    public function test_with_no_milestone_date_a_timing_estimate_is_required(): void
    {
        $answers = NprFixtures::validRequestAnswers();
        foreach (['budget_quote_date', 'firm_quote_date', 'first_delivery_date'] as $milestone) {
            $answers[$milestone] = ['state' => 'not_applicable', 'reason' => 'none'];
        }

        $result = NprFixtures::validator()->validateSubmission($answers, NprFixtures::validVariants());

        self::assertArrayHasKey('first_delivery_date', $result['errors']);
        self::assertContains(
            'At least one milestone date must be provided, or a timing estimate with basis must be supplied',
            $result['errors']['first_delivery_date'],
        );
    }

    public function test_the_sales_declaration_must_be_true(): void
    {
        $answers = NprFixtures::validRequestAnswers();
        $answers['sales_declaration'] = ['state' => 'provided', 'value' => false];

        $result = NprFixtures::validator()->validateSubmission($answers, NprFixtures::validVariants());

        self::assertContains('Sales declaration must be confirmed before submission', $result['errors']['sales_declaration']);
    }

    public function test_a_conditional_field_applies_only_when_its_parent_says_so(): void
    {
        $validator = NprFixtures::validator();
        $conditional = null;
        foreach ($validator->getAllFields() as $field) {
            $when = $field['appliesWhen'] ?? null;
            if (is_array($when) && ($when['operator'] ?? '') === 'equals') {
                $conditional = $field;
                break;
            }
        }
        self::assertNotNull($conditional, 'the dictionary has an equals condition');
        /** @var array{appliesWhen: array{field: string, value: mixed}} $conditional */
        $parent = $conditional['appliesWhen']['field'];
        $answers = [$parent => ['state' => 'provided', 'value' => $conditional['appliesWhen']['value']]];
        $variantScoped = ($validator->getFieldDefinition($parent)['scope'] ?? '') === 'variant';

        $applies = $validator->isFieldApplicable($conditional, $variantScoped ? [] : $answers, $variantScoped ? $answers : null);
        $withoutParent = $validator->isFieldApplicable($conditional, []);

        self::assertTrue($applies);
        self::assertFalse($withoutParent);
    }

    public function test_section_5_field_ids_come_from_the_dictionary(): void
    {
        $ids = NprFixtures::validator()->fieldIdsInSection(5);

        self::assertContains('conductor_size', $ids);
        self::assertContains('armour', $ids);
        self::assertNotContains('customer_name', $ids);
        self::assertCount(33, $ids);
    }

    public function test_screening_needs_the_section_10_assessment(): void
    {
        $result = NprFixtures::validator()->validateForScreening([]);

        self::assertFalse($result['valid']);
        self::assertNotSame([], $result['errors']);
    }
}
