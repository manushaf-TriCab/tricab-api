<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\Service\FormSubmissionRules;

/**
 * A submitted answer against its form version's schema. Found in the 4.1
 * browser pass on 23 Sep 2026: the demo uniform form accepted a boot size of
 * -1, because nothing on the server looked at the values at all.
 */
final class FormSubmissionRulesTest extends TestCase
{
    /** The demo Uniform request, as seeded — no bounds on boot size. */
    private const UNIFORM = ['fields' => [
        ['path' => 'shirtSize', 'label' => 'Shirt size', 'type' => 'select', 'required' => true, 'options' => [
            ['key' => 's', 'label' => 'Small'], ['key' => 'm', 'label' => 'Medium'], ['key' => 'l', 'label' => 'Large'],
        ]],
        ['path' => 'bootSize', 'label' => 'Boot size', 'type' => 'number', 'required' => true],
    ]];

    private const STARTER = ['fields' => [
        ['path' => 'preferredName', 'label' => 'Preferred name', 'type' => 'text', 'required' => true],
        ['path' => 'startDate', 'label' => 'Start date', 'type' => 'date', 'required' => true],
        ['path' => 'notes', 'label' => 'Anything HR should know', 'type' => 'textarea'],
        ['path' => 'confirm', 'label' => 'The details above are correct', 'type' => 'checkbox', 'required' => true],
    ]];

    public function test_a_negative_boot_size_is_refused_when_the_field_sets_no_minimum(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessage('Boot size');

        FormSubmissionRules::check(self::UNIFORM, ['shirtSize' => 'm', 'bootSize' => -1]);
    }

    public function test_a_valid_uniform_request_passes_whether_the_number_arrives_as_text_or_a_number(): void
    {
        FormSubmissionRules::check(self::UNIFORM, ['shirtSize' => 'm', 'bootSize' => 9]);
        FormSubmissionRules::check(self::UNIFORM, ['shirtSize' => 'm', 'bootSize' => '10.5']);
        $this->addToAssertionCount(2);
    }

    public function test_a_field_may_allow_negatives_and_cap_the_top(): void
    {
        $schema = ['fields' => [['path' => 'offset', 'label' => 'Offset', 'type' => 'number', 'min' => -5, 'max' => 5]]];

        FormSubmissionRules::check($schema, ['offset' => -5]);
        $this->addToAssertionCount(1);

        $this->expectExceptionMessage('must be at most 5');
        FormSubmissionRules::check($schema, ['offset' => 6]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        $starter = ['preferredName' => 'Sam', 'startDate' => '2026-10-01', 'confirm' => true];

        yield 'required text left blank' => [self::STARTER, ['preferredName' => '  '] + $starter, 'Preferred name'];
        yield 'required field missing' => [self::STARTER, array_diff_key($starter, ['startDate' => true]), 'Start date'];
        yield 'declaration unticked' => [self::STARTER, ['confirm' => false] + $starter, 'must be ticked'];
        yield 'checkbox as text' => [self::STARTER, ['confirm' => 'yes'] + $starter, 'not ticked or unticked'];
        yield 'impossible date' => [self::STARTER, ['startDate' => '2026-02-30'] + $starter, 'not a date'];
        yield 'date in another format' => [self::STARTER, ['startDate' => '01/10/2026'] + $starter, 'not a date'];
        yield 'text too long' => [self::STARTER, ['preferredName' => str_repeat('a', 1001)] + $starter, 'longer than 1000'];
        yield 'answer for a field the form lacks' => [self::STARTER, ['salary' => '1'] + $starter, 'not a field on this form'];
        yield 'select outside its options' => [self::UNIFORM, ['shirtSize' => 'xxl', 'bootSize' => 9], 'Must be one of: s, m, l'];
        yield 'number that is not one' => [self::UNIFORM, ['shirtSize' => 'm', 'bootSize' => 'nine'], 'not a number'];
        yield 'number as hex' => [self::UNIFORM, ['shirtSize' => 'm', 'bootSize' => '0x1A'], 'not a number'];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $payload
     */
    #[DataProvider('refusals')]
    public function test_it_refuses(array $schema, array $payload, string $message): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessage($message);

        FormSubmissionRules::check($schema, $payload);
    }

    /**
     * The message reaches audit summaries and migration findings, so it names
     * the field and the rule and never repeats what was typed.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function refusedValues(): iterable
    {
        $starter = ['preferredName' => 'Sam', 'startDate' => '2026-10-01', 'confirm' => true];

        yield 'date' => [self::STARTER, ['startDate' => '14/03/1987'] + $starter, '14/03/1987'];
        yield 'checkbox' => [self::STARTER, ['confirm' => 'visa-subclass-482'] + $starter, 'visa-subclass-482'];
        yield 'select' => [self::UNIFORM, ['shirtSize' => 'PA1234567', 'bootSize' => 9], 'PA1234567'];
        yield 'number' => [self::UNIFORM, ['shirtSize' => 'm', 'bootSize' => '0412 555 019'], '0412 555 019'];
        yield 'number out of bounds' => [self::UNIFORM, ['shirtSize' => 'm', 'bootSize' => -73512], '73512'];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $payload
     */
    #[DataProvider('refusedValues')]
    public function test_a_refusal_never_repeats_the_value(array $schema, array $payload, string $value): void
    {
        try {
            FormSubmissionRules::check($schema, $payload);
            self::fail('Expected a refusal.');
        } catch (InvalidOnboardingValue $refusal) {
            self::assertStringNotContainsString($value, $refusal->getMessage());
        }
    }

    public function test_optional_fields_may_be_left_empty(): void
    {
        FormSubmissionRules::check(self::STARTER, [
            'preferredName' => 'Sam',
            'startDate' => '2026-10-01',
            'notes' => '',
            'confirm' => true,
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_an_author_may_set_number_bounds_and_they_are_kept(): void
    {
        $normalised = FormSchemaRules::normalise([
            ['path' => 'bootSize', 'label' => 'Boot size', 'type' => 'number', 'min' => 1, 'max' => 20],
        ], 'hr');

        self::assertSame(1, $normalised['fields'][0]['min']);
        self::assertSame(20, $normalised['fields'][0]['max']);
    }

    public function test_an_author_cannot_set_a_minimum_above_the_maximum(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessage('above the maximum');

        FormSchemaRules::normalise([
            ['path' => 'bootSize', 'label' => 'Boot size', 'type' => 'number', 'min' => 20, 'max' => 1],
        ], 'hr');
    }
}
