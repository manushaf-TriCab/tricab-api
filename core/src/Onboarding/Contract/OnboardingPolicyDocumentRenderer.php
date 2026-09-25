<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Document\PolicyDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;

/**
 * Stamps a signed policy declaration onto the policy's own PDF (5.4). Null for
 * a policy with no paper sign-off.
 */
interface OnboardingPolicyDocumentRenderer
{
    public function render(PolicyDocumentContext $context): ?RenderedDocument;
}
