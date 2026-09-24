<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Contract\OnboardingFormDocumentRenderer;

/**
 * Chooses a form's paper-template mapper by its slug (5.4). A form with none —
 * the demo forms, anything authored on the platform — has no paper copy.
 */
final class TemplateOnboardingFormDocumentRenderer implements OnboardingFormDocumentRenderer
{
    /** @var array<string, FormPdfMapper> */
    private array $mappers = [];

    /**
     * @param list<FormPdfMapper> $mappers
     */
    public function __construct(private readonly string $templateDirectory, array $mappers)
    {
        foreach ($mappers as $mapper) {
            $this->mappers[$mapper->formSlug()] = $mapper;
        }
    }

    public static function withTriCabTemplates(string $basePath): self
    {
        return new self($basePath . '/resources/onboarding/form-templates', [new EmployeeDetailsPdfMapper(), ...LegalFormPdfMappers::all()]);
    }

    public function render(FormSubmissionForDocument $submission, array $answers): ?RenderedDocument
    {
        $mapper = $this->mappers[$submission->formSlug] ?? null;
        if ($mapper === null) {
            return null;
        }

        return new RenderedDocument(
            $mapper->documentKind(),
            'application/pdf',
            $mapper->render(new FormAnswers($answers), $submission, $this->templateDirectory),
        );
    }
}
