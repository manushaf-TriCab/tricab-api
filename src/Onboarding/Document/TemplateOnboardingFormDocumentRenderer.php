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
     * @param (\Closure(int, string): ?string)|null $photoBytes a photo's JPEG by owner and document id
     */
    public function __construct(private readonly string $templateDirectory, array $mappers, private readonly ?\Closure $photoBytes = null)
    {
        foreach ($mappers as $mapper) {
            $this->mappers[$mapper->formSlug()] = $mapper;
        }
    }

    /**
     * @param (\Closure(int, string): ?string)|null $photoBytes
     */
    public static function withTriCabTemplates(string $basePath, ?\Closure $photoBytes = null): self
    {
        return new self(
            $basePath . '/resources/onboarding/form-templates',
            [new EmployeeDetailsPdfMapper(), ...LegalFormPdfMappers::all(), new ChecklistPdfMapper(), new VehicleReportPdfMapper()],
            $photoBytes,
        );
    }

    public function render(FormSubmissionForDocument $submission, array $answers, string $moment = self::AT_SUBMISSION): ?RenderedDocument
    {
        $mapper = $this->mappers[$submission->formSlug] ?? null;
        if ($mapper === null || $mapper->renderedAt() !== $moment) {
            return null;
        }

        return new RenderedDocument(
            $mapper->documentKind(),
            'application/pdf',
            $mapper->render(
                new FormAnswers(
                    $answers,
                    $this->photoBytes === null ? null : fn (string $id): ?string => ($this->photoBytes)($submission->userId, $id),
                ),
                $submission,
                $this->templateDirectory,
            ),
        );
    }
}
