<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\Document\TemplateOnboardingFormDocumentRenderer;
use TriCab\Api\Onboarding\FormImport\ChecklistFormDefinition;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;

/** Phase 5.4 — the induction checklist. Checked by eye against HR-FOR-003-V006 on 24 Sep 2026. */
final class ChecklistDocumentTest extends TestCase
{
    public function test_it_is_a_valid_hr_form_that_hr_the_manager_and_the_starter_share(): void
    {
        $definition = ChecklistFormDefinition::definition();
        $schema = FormSchemaRules::normalise($definition['fields'], 'hr');

        $roles = array_count_values(array_map(static fn (array $f): string => FormSchemaRules::filledBy($f), $schema['fields']));
        self::assertSame(['hr' => 93, 'manager' => 2, 'starter' => 1], $roles);
        self::assertLessThanOrEqual(FormSchemaRules::MAX_FIELDS, count($schema['fields']));
    }

    public function test_its_copy_is_made_at_completion_only(): void
    {
        $renderer = TemplateOnboardingFormDocumentRenderer::withTriCabTemplates(dirname(__DIR__, 2));
        $submission = new FormSubmissionForDocument(1, 1, 1, ChecklistFormDefinition::SLUG, 'hr', 1, '2026-10-13T01:00:00Z', 'Sam');
        $answers = ['employeeName' => 'Sam', 'dayOne.paperwork.state' => 'completed', 'dayOne.paperwork.responsible' => 'Pat', 'dayOne.paperwork.completedOn' => '2026-10-06'];

        self::assertNull($renderer->render($submission, $answers));
        $document = $renderer->render($submission, $answers, 'completion');
        self::assertNotNull($document);
        self::assertSame('workflow', $document->documentKind);
    }
}
