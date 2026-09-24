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
    public const AT_SUBMISSION = 'submission';
    public const AT_COMPLETION = 'completion';

    /**
     * @param array<string, mixed> $answers the full payload — nested as submitted, or flat by path
     * @param 'submission'|'completion' $moment only a mapper made for this moment renders
     */
    public function render(FormSubmissionForDocument $submission, array $answers, string $moment = self::AT_SUBMISSION): ?RenderedDocument;
}
