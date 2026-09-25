<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;
use TriCab\Api\Onboarding\Document\TemplateOnboardingFormDocumentRenderer;
use TriCab\Api\Onboarding\FormImport\LegalFormDefinitions;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Tests\Onboarding\Domain\SignatureFieldTest;

/**
 * Phase 5.4 — the WHS Medical Questionnaire and the Confidentiality Deed.
 * Coordinates checked by eye against both templates on 24 Sep 2026.
 */
#[RequiresPhpExtension('gd')]
final class LegalFormDocumentsTest extends TestCase
{
    public function test_both_are_valid_forms_and_the_questionnaire_is_restricted_throughout(): void
    {
        foreach (LegalFormDefinitions::definitions() as $definition) {
            $schema = FormSchemaRules::normalise($definition['fields'], $definition['accessGroup']);
            self::assertCount(count($definition['fields']), $schema['fields'], $definition['slug']);
        }

        $medical = LegalFormDefinitions::medical();
        self::assertSame('restricted_hr', $medical['accessGroup']);
        foreach ($medical['fields'] as $field) {
            self::assertSame(DataClass::RESTRICTED_HR, $field['dataClass'], (string) json_encode($field['path']));
        }
        self::assertContains('signature', array_column($medical['fields'], 'type'));
        $signatures = [];
        foreach (LegalFormDefinitions::deed()['fields'] as $field) {
            if ($field['type'] === 'signature') {
                $signatures[] = $field['path'];
            }
        }
        self::assertSame(['employeeSignature', 'witnessSignature'], $signatures);
    }

    public function test_each_renders_onto_its_template_with_its_signatures(): void
    {
        $renderer = TemplateOnboardingFormDocumentRenderer::withTriCabTemplates(dirname(__DIR__, 2));
        $signature = SignatureFieldTest::png(400, 120);

        $cases = [
            [LegalFormDefinitions::MEDICAL_SLUG, 'restricted_hr', 1, 1, ['fullName' => 'Sam', 'timeOffWork' => ['answer' => 'yes', 'details' => 'Ankle'], 'signature' => $signature, 'signedDate' => '2026-09-24']],
            [LegalFormDefinitions::DEED_SLUG, 'hr', 5, 2, ['deedDate' => '2026-09-24', 'fullName' => 'Sam', 'employeeSignature' => $signature, 'witnessName' => 'Wendy', 'witnessSignature' => $signature]],
        ];
        foreach ($cases as [$slug, $group, $pages, $images, $answers]) {
            $document = $renderer->render(new FormSubmissionForDocument(1, 1, 1, $slug, $group, 1, '2026-09-24T01:00:00Z', 'Sam'), $answers);
            self::assertNotNull($document, $slug);
            self::assertSame('legal_forms', $document->documentKind);
            $blank = $renderer->render(new FormSubmissionForDocument(1, 1, 1, $slug, $group, 1, '2026-09-24T01:00:00Z', 'Sam'), []);
            self::assertNotNull($blank);
            self::assertSame($images, substr_count($document->bytes, '/Subtype /Image') - substr_count($blank->bytes, '/Subtype /Image'), $slug . ' signatures drawn');
            $file = tempnam(sys_get_temp_dir(), 'pdf');
            self::assertIsString($file);
            file_put_contents($file, $document->bytes);
            self::assertSame($pages, (new Fpdi())->setSourceFile($file), $slug);
            unlink($file);
        }
    }

    public function test_a_missing_or_broken_signature_is_left_blank_rather_than_failing(): void
    {
        $renderer = TemplateOnboardingFormDocumentRenderer::withTriCabTemplates(dirname(__DIR__, 2));

        $document = $renderer->render(
            new FormSubmissionForDocument(1, 1, 1, LegalFormDefinitions::MEDICAL_SLUG, 'restricted_hr', 1, '2026-09-24T01:00:00Z', 'Sam'),
            ['fullName' => 'Sam', 'signature' => 'data:image/png;base64,broken'],
        );

        $blank = $renderer->render(
            new FormSubmissionForDocument(1, 1, 1, LegalFormDefinitions::MEDICAL_SLUG, 'restricted_hr', 1, '2026-09-24T01:00:00Z', 'Sam'),
            ['fullName' => 'Sam'],
        );
        self::assertNotNull($document);
        self::assertNotNull($blank);
        self::assertSame(substr_count($blank->bytes, '/Subtype /Image'), substr_count($document->bytes, '/Subtype /Image'));
    }
}
