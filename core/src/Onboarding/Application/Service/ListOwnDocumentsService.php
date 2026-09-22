<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentView;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;

/**
 * The documents belonging to the signed-in person.
 *
 * Takes a `$userId` and nothing else, like every other self-service read: no
 * filter, no kind parameter, no paging cursor that could name somebody else's
 * page. A person's own document list is a handful of items, and every parameter
 * that is not there is a parameter that cannot be turned into a probe.
 *
 * Listing is **not** audited, and reading is. The distinction is the one
 * `audit.OnboardingDocumentAccessEvents` was designed around: metadata says a
 * document exists, content is the payslip PIN. Auditing the list as well would
 * write a row every time somebody opens the Documents tab, and the noise is what
 * makes a real read hard to find.
 *
 * Phase 5 is what generates documents, so today this returns an empty list for
 * everybody. The access path is built now because access control over an empty
 * table is the version of this that is safe to get wrong.
 */
final readonly class ListOwnDocumentsService
{
    public function __construct(
        private OnboardingDocumentRepository $documents,
        private OnboardingSubmissionGate $gate,
    ) {
    }

    /**
     * @return list<OnboardingDocumentView>
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed
     */
    public function forUser(int $userId): array
    {
        $this->gate->assertMayRead($userId);

        return $this->documents->listDocuments($userId);
    }
}
