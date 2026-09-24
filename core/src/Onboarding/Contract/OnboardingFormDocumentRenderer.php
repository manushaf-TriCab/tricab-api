<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;

/**
 * Paints a submitted form onto its TriCab paper template (5.4), as the source
 * did with pdf-lib.
 *
 * Returns null for a form with no template — a demo form, or one authored on
 * the platform — which is not an error: that form simply has no paper copy.
 */
interface OnboardingFormDocumentRenderer
{
    /**
     * @param array<string, mixed> $answers the full, nested payload as submitted, before the split
     */
    public function render(FormSubmissionForDocument $submission, array $answers): ?RenderedDocument;
}
