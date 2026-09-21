<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\FormVersionNotClassified;
use TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter;
use TriCab\Core\Onboarding\Domain\Service\FormVersionPublishGuard;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * The per-field classification, at both ends: the publish guard that makes it
 * mandatory, and the splitter that acts on it.
 *
 * This pair closes the one fail-open gap in the classification design. The
 * obvious alternative — "a field nobody classified is ordinary" — writes an
 * unclassified bank account into the workflow schema, where reports, the
 * reviewer queue and every ordinary query can read it, and leaves nothing able
 * to tell "nobody looked at this" apart from "somebody decided it was fine".
 */
final class FormClassificationTest extends TestCase
{
    /**
     * The real Employee Details classification, approved by the owner on
     * 21 September 2026 and taken from the source form's own field list
     * (`api/_lib/forms/employee-details.ts` in tricab-training-vercel).
     *
     * Kept here rather than in a fixture because it is the worked example the
     * whole design exists for: one form, one payload, three classes.
     *
     * @return array<string, int>
     */
    private function employeeDetailsClassification(): array
    {
        $ordinary = ['surname', 'givenName', 'preferredName', 'mobile', 'declarationAccepted'];

        $payroll = [
            'dateOfBirth', 'address', 'postcode', 'homePhone',
            'payslipEmail', 'payslipPin',
            'bankAccounts.0.bankName', 'bankAccounts.0.branch', 'bankAccounts.0.bsb',
            'bankAccounts.0.accountNumber', 'bankAccounts.0.accountName',
            'bankAccounts.0.allocationType', 'bankAccounts.0.allocationValue',
            'bankAccounts.1.bankName', 'bankAccounts.1.branch', 'bankAccounts.1.bsb',
            'bankAccounts.1.accountNumber', 'bankAccounts.1.accountName',
            'bankAccounts.1.allocationType', 'bankAccounts.1.allocationValue',
        ];

        $restricted = [
            'medicalConditions',
            'immigrationStatus', 'immigrationOtherDetails',
            'passportNumber', 'visaNumberAndSubclass', 'visaExpiry',
            'aboriginalOrTorresStraitIslander', 'culturalIdentity', 'genderIdentity',
            'mealPreferences.vegetarian', 'mealPreferences.vegan',
            'mealPreferences.other', 'mealPreferences.otherDetails',
            'emergencyContacts.0.fullName', 'emergencyContacts.0.relationship', 'emergencyContacts.0.phone',
            'emergencyContacts.1.fullName', 'emergencyContacts.1.relationship', 'emergencyContacts.1.phone',
        ];

        $map = [];
        foreach ($ordinary as $path) {
            $map[$path] = DataClass::ORDINARY_HR;
        }
        foreach ($payroll as $path) {
            $map[$path] = DataClass::PAYROLL;
        }
        foreach ($restricted as $path) {
            $map[$path] = DataClass::RESTRICTED_HR;
        }

        return $map;
    }

    // ------------------------------------------------------------ publish guard

    public function test_an_ordinary_hr_form_needs_no_classification(): void
    {
        $this->expectNotToPerformAssertions();

        FormVersionPublishGuard::assertPublishable(
            FormVersionPublishGuard::ACCESS_GROUP_HR,
            ['managerName', 'checklistComplete'],
            [],
        );
    }

    public function test_a_payroll_form_cannot_be_published_with_an_unclassified_field(): void
    {
        try {
            FormVersionPublishGuard::assertPublishable(
                FormVersionPublishGuard::ACCESS_GROUP_PAYROLL,
                ['bsb', 'accountNumber', 'superFundName'],
                ['bsb', 'accountNumber'],
            );
            self::fail('Publishing should have been refused.');
        } catch (FormVersionNotClassified $e) {
            self::assertSame(['superFundName'], $e->fieldPaths);
            self::assertStringContainsString('superFundName', $e->getMessage());
        }
    }

    public function test_a_restricted_form_is_guarded_too(): void
    {
        $this->expectException(FormVersionNotClassified::class);

        FormVersionPublishGuard::assertPublishable(
            FormVersionPublishGuard::ACCESS_GROUP_RESTRICTED_HR,
            ['medicalConditions'],
            [],
        );
    }

    public function test_a_fully_classified_payroll_form_publishes(): void
    {
        $this->expectNotToPerformAssertions();

        $classification = $this->employeeDetailsClassification();

        FormVersionPublishGuard::assertPublishable(
            FormVersionPublishGuard::ACCESS_GROUP_PAYROLL,
            array_keys($classification),
            array_keys($classification),
        );
    }

    /**
     * An HR user fixing this works down the form. An alphabetised list of twelve
     * paths makes them hunt for each one.
     */
    public function test_missing_fields_are_reported_in_schema_order(): void
    {
        self::assertSame(
            ['b', 'd'],
            FormVersionPublishGuard::unclassifiedFields(['a', 'b', 'c', 'd'], ['a', 'c']),
        );
    }

    /**
     * Changing a form's access group to payroll makes its existing fields
     * unclassified, and the next publish refuses. That is why the check is per
     * version rather than per definition.
     */
    public function test_promoting_an_hr_form_to_payroll_makes_it_unpublishable(): void
    {
        $schema = ['managerName', 'bsb'];

        FormVersionPublishGuard::assertPublishable(FormVersionPublishGuard::ACCESS_GROUP_HR, $schema, []);

        $this->expectException(FormVersionNotClassified::class);
        FormVersionPublishGuard::assertPublishable(FormVersionPublishGuard::ACCESS_GROUP_PAYROLL, $schema, []);
    }

    // ---------------------------------------------------------------- splitter

    public function test_a_payload_splits_into_three_by_class(): void
    {
        $split = FormPayloadSplitter::split(
            ['surname' => 'Moretti', 'bsb' => '063-000', 'medicalConditions' => 'none'],
            [
                'surname' => DataClass::ORDINARY_HR,
                'bsb' => DataClass::PAYROLL,
                'medicalConditions' => DataClass::RESTRICTED_HR,
            ],
        );

        self::assertSame(['surname' => 'Moretti'], $split['ordinary']);
        self::assertSame(['bsb' => '063-000'], $split['payroll']);
        self::assertSame(['medicalConditions' => 'none'], $split['restricted']);
    }

    /**
     * The property the whole split exists for: nothing sensitive is left in the
     * part that goes to the workflow schema.
     */
    public function test_no_payroll_or_restricted_field_reaches_the_ordinary_part(): void
    {
        $classification = $this->employeeDetailsClassification();
        $payload = array_map(static fn (): string => 'x', $classification);

        $split = FormPayloadSplitter::split($payload, $classification);

        foreach (array_keys($split['ordinary']) as $path) {
            self::assertSame(
                DataClass::ORDINARY_HR,
                $classification[$path],
                $path . ' is not ordinary and must not be in the workflow schema.',
            );
        }

        self::assertArrayNotHasKey('payslipPin', $split['ordinary']);
        self::assertArrayHasKey('payslipPin', $split['payroll']);
        self::assertArrayNotHasKey('medicalConditions', $split['ordinary']);
        self::assertArrayHasKey('medicalConditions', $split['restricted']);
    }

    /**
     * Fails closed. A form version published as `hr` and later changed to
     * `payroll` has unclassified fields and live allocations; refusing here
     * means the worst case is a submission somebody has to fix, rather than a
     * bank account written in clear.
     */
    public function test_an_unclassified_field_is_refused_rather_than_defaulted(): void
    {
        try {
            FormPayloadSplitter::split(
                ['surname' => 'Moretti', 'superFundAbn' => '123'],
                ['surname' => DataClass::ORDINARY_HR],
            );
            self::fail('An unclassified field should be refused.');
        } catch (FormVersionNotClassified $e) {
            self::assertSame(['superFundAbn'], $e->fieldPaths);
        }
    }

    public function test_it_reports_whether_a_restricted_half_exists(): void
    {
        $classification = $this->employeeDetailsClassification();

        self::assertTrue(FormPayloadSplitter::hasRestrictedData(
            FormPayloadSplitter::split(['bsb' => '1'], ['bsb' => DataClass::PAYROLL]),
        ));
        self::assertTrue(FormPayloadSplitter::hasRestrictedData(
            FormPayloadSplitter::split(['medicalConditions' => 'x'], ['medicalConditions' => DataClass::RESTRICTED_HR]),
        ));
        self::assertFalse(FormPayloadSplitter::hasRestrictedData(
            FormPayloadSplitter::split(['surname' => 'x'], ['surname' => DataClass::ORDINARY_HR]),
        ));

        self::assertNotSame([], $classification);
    }

    // --------------------------------------------------------------- flattening

    public function test_nested_payloads_flatten_to_dotted_leaf_paths(): void
    {
        self::assertSame(
            [
                'surname' => 'Moretti',
                'bankAccounts.0.bsb' => '063-000',
                'bankAccounts.0.accountNumber' => '12345678',
                'mealPreferences.vegan' => true,
            ],
            FormPayloadSplitter::flatten([
                'surname' => 'Moretti',
                'bankAccounts' => [['bsb' => '063-000', 'accountNumber' => '12345678']],
                'mealPreferences' => ['vegan' => true],
            ]),
        );
    }

    /**
     * An empty array is an answer somebody gave. Dropping it would silently lose
     * a field the classification expects, turning a valid submission into an
     * unclassified-field refusal that names a field the person never saw.
     */
    public function test_an_empty_value_survives_flattening(): void
    {
        self::assertSame(
            ['mealPreferences' => [], 'surname' => 'Moretti'],
            FormPayloadSplitter::flatten(['mealPreferences' => [], 'surname' => 'Moretti']),
        );
    }

    /**
     * Classification names a leaf, never an object. Classifying `bankAccounts`
     * as a whole would mean a field added inside it later inherits a decision
     * nobody made about it.
     */
    public function test_the_real_employee_details_form_round_trips(): void
    {
        $classification = $this->employeeDetailsClassification();

        $flat = FormPayloadSplitter::flatten([
            'surname' => 'Moretti',
            'givenName' => 'Ric',
            'preferredName' => '',
            'mobile' => '0400 000 000',
            'declarationAccepted' => true,
            'dateOfBirth' => '1990-01-01',
            'address' => '1 Example St',
            'postcode' => '3000',
            'homePhone' => '',
            'payslipEmail' => 'ricm@example.com',
            'payslipPin' => '4821',
            'bankAccounts' => [
                ['bankName' => 'A', 'branch' => 'B', 'bsb' => '063-000', 'accountNumber' => '1', 'accountName' => 'R M', 'allocationType' => '', 'allocationValue' => ''],
                ['bankName' => '', 'branch' => '', 'bsb' => '', 'accountNumber' => '', 'accountName' => '', 'allocationType' => '', 'allocationValue' => ''],
            ],
            'medicalConditions' => 'none',
            'immigrationStatus' => 'citizen',
            'immigrationOtherDetails' => '',
            'passportNumber' => '',
            'visaNumberAndSubclass' => '',
            'visaExpiry' => '',
            'aboriginalOrTorresStraitIslander' => 'no',
            'culturalIdentity' => '',
            'genderIdentity' => '',
            'mealPreferences' => ['vegetarian' => false, 'vegan' => false, 'other' => false, 'otherDetails' => ''],
            'emergencyContacts' => [
                ['fullName' => 'A N Other', 'relationship' => 'partner', 'phone' => '0400 111 111'],
                ['fullName' => '', 'relationship' => '', 'phone' => ''],
            ],
        ]);

        $split = FormPayloadSplitter::split($flat, $classification);

        self::assertSame(
            ['surname', 'givenName', 'preferredName', 'mobile', 'declarationAccepted'],
            array_keys($split['ordinary']),
        );
        self::assertCount(20, $split['payroll']);
        self::assertCount(19, $split['restricted']);
        self::assertTrue(FormPayloadSplitter::hasRestrictedData($split));
    }
}
