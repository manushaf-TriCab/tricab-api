<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentAccess;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy;
use TriCab\Core\Onboarding\Module;

/**
 * Opens one of a person's own documents.
 *
 * ## Why this is a service and not two lines in an action
 *
 * Because three things have to happen in one order and none of them may be
 * skipped: resolve against the asker's own id, decide the delivery mode, write
 * the audit row. An action that did them inline is an action somebody copies for
 * Phase 4's HR route, and the copy is where the audit call gets dropped.
 *
 * ## The leaver window applies, and applies as a READ
 *
 * {@see OnboardingSubmissionGate::assertMayRead()} rather than `assertMaySubmit()`.
 * A departed person collecting their own signed paperwork on the way out is the
 * case the fourteen-day window was decided for; refusing it would make the window
 * decorative.
 *
 * ## Fail closed, and the audit is written anyway
 *
 * {@see DocumentDeliveryPolicy} streams anything it cannot classify. The cost of
 * being wrong that way is bandwidth; the cost of being wrong the other way is a
 * bearer URL for a medical questionnaire that nobody can attribute afterwards.
 * The audit row is written for every read this service authorizes, including the
 * C0–C2 ones the policy says may be signed — the policy separates
 * `mustBeAudited()` from `mustBeStreamed()` for a future where they diverge, and
 * on the self-service route the volume is one person's own handful of documents,
 * so there is no reason to record less.
 */
final readonly class OpenOwnDocumentService
{
    public function __construct(
        private OnboardingDocumentRepository $documents,
        private OnboardingSubmissionGate $gate,
    ) {
    }

    /**
     * @param 'read'|'download'|'print'|'export' $accessKind
     *
     * @throws OnboardingDocumentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed
     */
    public function open(
        int $userId,
        string $documentPublicId,
        string $accessKind = 'download',
        ?string $correlationId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): OnboardingDocumentAccess {
        $this->gate->assertMayRead($userId);

        $document = $this->documents->findDocument($userId, $documentPublicId);

        if ($document === null) {
            throw OnboardingDocumentNotFound::forThisPerson();
        }

        $this->documents->recordAccess(
            $document,
            $userId,
            $accessKind,
            // The permission that admitted them, recorded rather than inferred.
            // Phase 4's HR route will write a different one against the same
            // document, and "who read this, under what authority" is the
            // question the table exists to answer.
            Module::PERMISSION_SELF_READ,
            $correlationId,
            $ipAddress,
            $userAgent,
        );

        return new OnboardingDocumentAccess(
            $document,
            DocumentDeliveryPolicy::modeFor($document->dataClass),
        );
    }
}
