<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;

/**
 * One form's answers onto one version of its paper template. A replacement
 * template is a new mapper, never moved coordinates under historical
 * submissions — the source's rule.
 */
interface FormPdfMapper
{
    public function formSlug(): string;

    /** One of `CK_onboarding_DocumentReferences_Kind`. */
    public function documentKind(): string;

    /**
     * When the paper copy is made: `submission` for a form the starter fills
     * alone; `completion` for one HR and the manager fill parts of, whose copy
     * is only whole once a reviewer accepts it (5.4).
     */
    public function renderedAt(): string;

    public function render(FormAnswers $answers, FormSubmissionForDocument $submission, string $templateDirectory): string;
}
