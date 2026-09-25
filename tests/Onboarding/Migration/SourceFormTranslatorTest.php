<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\FormImport\ChecklistFormDefinition;
use TriCab\Api\Onboarding\FormImport\EmployeeDetailsFormDefinition;
use TriCab\Api\Onboarding\FormImport\LegalFormDefinitions;
use TriCab\Api\Onboarding\FormImport\VehicleReportFormDefinition;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Migration\Form\SourceFormTranslator;
use TriCab\Core\Onboarding\Migration\Form\TranslatedForm;

/**
 * Phase 7.2 — each TriCab form, filled in the source's shape, lands on the
 * platform's own fields: every path exists on the platform form, in the part
 * whoever filled it owns, every choice is one the platform offers, and nothing
 * is left behind except what is named.
 *
 * The samples follow the source's TypeScript shapes in
 * `tricab-training-vercel/api/_lib/forms/*.ts`.
 */
final class SourceFormTranslatorTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgo=';

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<array<string, mixed>>, list<string>}>
     */
    public static function forms(): iterable
    {
        yield 'employee details' => [SourceFormTranslator::EMPLOYEE_DETAILS, self::employeeDetails(), EmployeeDetailsFormDefinition::fields(), []];
        yield 'medical questionnaire' => [SourceFormTranslator::MEDICAL, self::medical(), LegalFormDefinitions::medical()['fields'], []];
        yield 'deed' => [SourceFormTranslator::DEED, self::deed(), LegalFormDefinitions::deed()['fields'], []];
        yield 'checklist' => [SourceFormTranslator::CHECKLIST, self::checklist(), ChecklistFormDefinition::definition()['fields'], ['dayOne.1.notes', 'managerAcknowledgement.name']];
        yield 'vehicle report' => [SourceFormTranslator::VEHICLE, self::vehicle(), VehicleReportFormDefinition::definition()['fields'], ['driver.fullName']];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<array<string, mixed>> $fields
     * @param list<string> $expectedDropped
     */
    #[DataProvider('forms')]
    public function test_a_filled_source_form_lands_on_the_platform_s_fields(string $slug, array $source, array $fields, array $expectedDropped): void
    {
        $translated = SourceFormTranslator::translate($slug, $source);
        $byPath = [];
        foreach ($fields as $field) {
            if (is_string($field['path'] ?? null)) {
                $byPath[$field['path']] = $field;
            }
        }

        foreach (['trainee' => $translated->trainee, 'hr' => $translated->hr, 'manager' => $translated->manager] as $part => $answers) {
            foreach ($answers as $path => $value) {
                self::assertArrayHasKey($path, $byPath, sprintf('%s: %s is not a platform field', $slug, $path));
                $filledBy = FormSchemaRules::filledBy($byPath[$path]);
                self::assertSame($part === 'trainee' ? FormSchemaRules::FILLED_BY_STARTER : $part, $filledBy, sprintf('%s: %s belongs to %s', $slug, $path, $filledBy));
                if (($byPath[$path]['type'] ?? null) === 'select') {
                    $options = $byPath[$path]['options'] ?? null;
                    self::assertIsArray($options);
                    self::assertContains($value, array_column($options, 'key'), sprintf('%s: %s = %s is not an option', $slug, $path, (string) $value));
                }
            }
        }
        self::assertSame($expectedDropped, $translated->dropped);
        self::assertGreaterThan(0, count($translated->trainee) + count($translated->hr), 'something was carried');
    }

    public function test_employee_details_moves_arrays_onto_numbered_fields(): void
    {
        $t = SourceFormTranslator::translate(SourceFormTranslator::EMPLOYEE_DETAILS, self::employeeDetails());

        self::assertSame('062000', $t->trainee['bank1.bsb']);
        self::assertSame('Westpac', $t->trainee['bank2.bankName']);
        self::assertSame('Sam Ng', $t->trainee['emergency2.fullName']);
        self::assertTrue($t->trainee['meals.vegetarian']);
        self::assertFalse($t->trainee['meals.vegan'], 'an unticked box is an answer');
    }

    public function test_the_checklist_splits_hr_manager_and_trainee_and_a_pending_item_is_unanswered(): void
    {
        $t = SourceFormTranslator::translate(SourceFormTranslator::CHECKLIST, self::checklist());

        self::assertSame('completed', $t->hr['dayOne.siteTour.state']);
        self::assertSame('Jo Supervisor', $t->hr['dayOne.siteTour.responsible']);
        self::assertArrayNotHasKey('weekOne.meetTeam.state', $t->hr, 'pending is unanswered');
        self::assertSame(['managerAcknowledged' => true, 'managerAcknowledgedOn' => '2026-08-04'], $t->manager);
        self::assertSame(['employeeAcknowledged' => true], $t->trainee);
    }

    public function test_the_vehicle_report_carries_its_photos_for_storage(): void
    {
        $t = SourceFormTranslator::translate(SourceFormTranslator::VEHICLE, self::vehicle());

        self::assertSame([['sourceId' => 'p1', 'filename' => 'front.jpg', 'base64' => '/9j/4AAQ']], $t->photos);
        self::assertArrayNotHasKey('photos', $t->hr, 'the answer is written once the photo is stored');
        self::assertSame('Driver', $t->trainee['driverPosition']);
    }

    public function test_a_form_without_a_mapping_is_read_in_the_platform_s_own_shape(): void
    {
        $t = SourceFormTranslator::translate('demo-uniform-sizes', ['shirt' => 'M', 'boots' => ['size' => 9, 'steelCap' => true], 'notes' => '']);

        self::assertEquals(new TranslatedForm(['shirt' => 'M', 'boots.size' => 9, 'boots.steelCap' => true]), $t);
    }

    public function test_an_empty_draft_translates_to_nothing(): void
    {
        foreach ([SourceFormTranslator::EMPLOYEE_DETAILS, SourceFormTranslator::CHECKLIST, SourceFormTranslator::VEHICLE] as $slug) {
            $t = SourceFormTranslator::translate($slug, []);
            self::assertSame([[], [], [], [], []], [$t->trainee, $t->hr, $t->manager, $t->photos, $t->dropped]);
        }
    }

    /** @return array<string, mixed> */
    private static function employeeDetails(): array
    {
        return [
            'surname' => 'Example', 'givenName' => 'Alex', 'preferredName' => 'Al', 'dateOfBirth' => '1990-02-03',
            'address' => '1 Test St, Testville VIC', 'postcode' => '3000', 'homePhone' => '', 'mobile' => '0400000000',
            'aboriginalOrTorresStraitIslander' => 'no', 'culturalIdentity' => 'Synthetic', 'genderIdentity' => 'nonbinary',
            'mealPreferences' => ['vegetarian' => true, 'vegan' => false, 'other' => true, 'otherDetails' => 'No nuts'],
            'medicalConditions' => 'None', 'immigrationStatus' => 'other', 'immigrationOtherDetails' => 'Working holiday',
            'passportNumber' => 'X0000000', 'visaNumberAndSubclass' => '417', 'visaExpiry' => '2027-01-01',
            'bankAccounts' => [
                ['bankName' => 'CBA', 'branch' => 'Melbourne', 'bsb' => '062000', 'accountNumber' => '12345678', 'accountName' => 'A Example', 'allocationType' => 'percentage', 'allocationValue' => '80'],
                ['bankName' => 'Westpac', 'branch' => 'Geelong', 'bsb' => '033000', 'accountNumber' => '87654321', 'accountName' => 'A Example', 'allocationType' => 'percentage', 'allocationValue' => '20'],
            ],
            'payslipEmail' => 'alex@example.test', 'payslipPin' => '1234',
            'emergencyContacts' => [
                ['fullName' => 'Pat Example', 'relationship' => 'Parent', 'phone' => '0400000001'],
                ['fullName' => 'Sam Ng', 'relationship' => 'Friend', 'phone' => '0400000002'],
            ],
            'declarationAccepted' => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function medical(): array
    {
        return [
            'fullName' => 'Alex Example', 'address' => '1 Test St', 'contactNumber' => '0400000000',
            'responses' => [
                ['id' => 'time-off-work', 'answer' => 'no', 'details' => ''],
                ['id' => 'job-restrictions', 'answer' => 'yes', 'details' => 'No heavy lifting'],
                ['id' => 'treatment-or-medication', 'answer' => 'no', 'details' => ''],
                ['id' => 'special-requirements', 'answer' => 'no', 'details' => ''],
                ['id' => 'other-support-condition', 'answer' => 'no', 'details' => ''],
            ],
            'signatureDataUrl' => self::PNG, 'signedDate' => '2026-08-01', 'declarationAccepted' => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function deed(): array
    {
        return [
            'deedDate' => '2026-08-01', 'fullName' => 'Alex Example', 'address' => '1 Test St',
            'employeeSignatureDataUrl' => self::PNG, 'employeeSignedDate' => '2026-08-01',
            'witnessName' => 'Jo Witness', 'witnessSignatureDataUrl' => self::PNG, 'declarationAccepted' => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function checklist(): array
    {
        $item = static fn (string $id, string $state, string $notes = ''): array => [
            'id' => $id, 'state' => $state,
            'responsiblePerson' => $state === 'pending' ? '' : 'Jo Supervisor',
            'completedOn' => $state === 'pending' ? '' : '2026-08-03', 'notes' => $notes,
        ];

        return [
            'employeeName' => 'Alex Example', 'startDate' => '2026-08-03', 'position' => 'Storeperson',
            'managerName' => 'Jo Supervisor', 'department' => 'Warehouse', 'branch' => 'Melbourne',
            'dayOne' => [$item('paperwork', 'completed'), $item('site-tour', 'completed', 'Took an hour'), $item('checks', 'not_applicable')],
            'dayOneNotes' => 'Good first day',
            'weekOne' => [$item('meet-team', 'pending'), $item('it-onboarding', 'completed')],
            'employeeAcknowledgement' => ['accepted' => true, 'name' => 'Alex Example', 'date' => '2026-08-04'],
            'managerAcknowledgement' => ['accepted' => true, 'name' => 'Jo Supervisor', 'date' => '2026-08-04'],
            'submittedToHrBy' => 'Jo Supervisor', 'submittedToHrOn' => '2026-08-05',
        ];
    }

    /** @return array<string, mixed> */
    private static function vehicle(): array
    {
        return [
            'inspectionDate' => '2026-08-02', 'inspectedBy' => 'Jo Fleet', 'registration' => 'SYN123', 'year' => '2022',
            'make' => 'Toyota', 'model' => 'Hilux', 'bodyType' => 'Ute', 'colour' => 'White', 'transmission' => 'Auto',
            'odometer' => '42000', 'nextServiceDue' => '50000 km', 'firstAidKit' => 'yes', 'logBook' => 'no',
            'comments' => 'Scratch on tray', 'overallCondition' => 'used',
            'photos' => [['id' => 'p1', 'filename' => 'front.jpg', 'mimeType' => 'image/jpeg', 'base64' => '/9j/4AAQ', 'width' => 800, 'height' => 600, 'uploadedAt' => '2026-08-02T01:00:00Z']],
            'driver' => ['fullName' => 'Alex Example', 'position' => 'Driver', 'licenceNumber' => 'L000', 'licenceExpiry' => '2028-01-01', 'licenceCopyAttached' => true, 'acknowledged' => true, 'handoverDate' => '2026-08-02'],
        ];
    }
}
