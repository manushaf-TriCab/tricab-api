<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentInput;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;

/**
 * Marks a submitted attempt onto its module's paper assessment sheet (5.4).
 * Null for a module with no sheet, or an attempt whose question count matches
 * no version of it.
 */
interface OnboardingAssessmentDocumentRenderer
{
    public function render(AssessmentDocumentInput $input): ?RenderedDocument;
}
