<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;
use TriCab\Api\Onboarding\Document\TemplateOnboardingFormDocumentRenderer;
use TriCab\Api\Onboarding\FormImport\EmployeeDetailsFormDefinition;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Phase 5.4 — HR-FOR-004-V007 as a platform form, and its paper copy.
 *
 * The coordinates themselves were checked by eye against the template on
 * 24 Sep 2026. What a test can hold is the contract between the two halves: the
 * definition is a valid payroll form, it classifies what architecture §5 says,
 * and every path the mapper draws is a path the form has.
 */
final class EmployeeDetailsDocumentTest extends TestCase
{
    public function test_the_definition_is_a_valid_payroll_form(): void
    {
        $schema = FormSchemaRules::normalise(EmployeeDetailsFormDefinition::fields(), EmployeeDetailsFormDefinition::ACCESS_GROUP);

        self::assertCount(count(EmployeeDetailsFormDefinition::fields()), $schema['fields']);
    }

    public function test_bank_details_and_the_pin_are_payroll_and_health_and_identity_are_restricted(): void
    {
        $classes = array_column(EmployeeDetailsFormDefinition::fields(), 'dataClass', 'path');

        foreach (['bank1.bsb', 'bank1.accountNumber', 'bank2.accountNumber', 'payslipPin', 'payslipEmail', 'dateOfBirth', 'address'] as $path) {
            self::assertSame(DataClass::PAYROLL, $classes[$path], $path);
        }
        foreach (['medicalConditions', 'immigrationStatus', 'passportNumber', 'visaExpiry', 'aboriginalOrTorresStraitIslander', 'genderIdentity', 'meals.vegan', 'emergency1.phone'] as $path) {
            self::assertSame(DataClass::RESTRICTED_HR, $classes[$path], $path);
        }
        self::assertSame(DataClass::ORDINARY_HR, $classes['surname']);
    }

    public function test_every_path_the_mapper_reads_is_on_the_form(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Onboarding/Document/EmployeeDetailsPdfMapper.php');
        self::assertIsString($source);
        preg_match_all('/->(?:text|ticked)\(\s*"?\'?([A-Za-z0-9_.{}$]+)/', $source, $m);

        self::assertGreaterThanOrEqual(30, count(array_unique($m[1])), 'the pattern still finds the mapper\'s reads');
        $paths = EmployeeDetailsFormDefinition::paths();
        foreach (array_unique($m[1]) as $read) {
            $expanded = str_contains($read, '{$prefix}')
                ? [str_replace('{$prefix}', 'bank1', $read), str_replace('{$prefix}', 'emergency1', $read)]
                : [$read];
            self::assertTrue(
                array_intersect($expanded, $paths) !== [],
                $read . ' is drawn but is not a field on the form',
            );
        }
    }

    public function test_a_submission_renders_a_one_page_pdf_on_the_template(): void
    {
        $renderer = TemplateOnboardingFormDocumentRenderer::withTriCabTemplates(dirname(__DIR__, 2));

        $document = $renderer->render(
            new FormSubmissionForDocument(1, 1, 1, 'employee-details', 'payroll', 1, '2026-09-24T01:00:00Z', 'Sam Synthetic'),
            ['surname' => 'Synthetic', 'givenName' => 'Sam', 'bank1' => ['bsb' => '063-000'], 'meals' => ['vegan' => true], 'payslipPin' => '4321'],
        );

        self::assertNotNull($document);
        self::assertSame('employee_details', $document->documentKind);
        self::assertStringStartsWith('%PDF-', $document->bytes);
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertIsString($file);
        file_put_contents($file, $document->bytes);
        self::assertSame(1, (new Fpdi())->setSourceFile($file));
        unlink($file);
    }

    public function test_a_form_without_a_template_renders_nothing(): void
    {
        $renderer = TemplateOnboardingFormDocumentRenderer::withTriCabTemplates(dirname(__DIR__, 2));

        self::assertNull($renderer->render(new FormSubmissionForDocument(1, 1, 1, 'demo-uniform-request', 'hr', 1, '2026-09-24T01:00:00Z', 'Sam'), []));
    }
}
