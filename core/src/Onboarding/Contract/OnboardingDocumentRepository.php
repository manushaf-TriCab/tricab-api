<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentView;

/**
 * The documents generated from one person's completed work.
 *
 * Documents are not allocated, so there is no assignment to key them off: they
 * appear because something was finished. Phase 5 is what generates them. Phase
 * 3.3 builds the way in, because access control on an empty table is the version
 * of this that is safe to get wrong.
 *
 * ## Reading is a state change here
 *
 * {@see recordAccess()} is not optional bookkeeping. For C3 and C4 it is the
 * entire argument for streaming rather than handing out a signed URL — see
 * {@see \TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy}. An
 * implementation writes to `audit.OnboardingDocumentAccessEvents`, which is
 * append-only by trigger.
 *
 * The audit row is written **before** the bytes are served, not after. A read
 * that failed halfway is still a read: the actor was authorized, the reference
 * was resolved, and something was probably transferred. Logging afterwards
 * records only the reads that finished, which is the wrong set.
 */
interface OnboardingDocumentRepository
{
    /**
     * This person's own documents, newest first, deleted ones excluded.
     *
     * @return list<OnboardingDocumentView>
     */
    public function listDocuments(int $userId): array;

    /**
     * Null when the document is not this person's, has been deleted, or never
     * existed — indistinguishable on purpose (invariant 4).
     */
    public function findDocument(int $userId, string $documentPublicId): ?OnboardingDocumentRef;

    /**
     * Records that somebody read a document.
     *
     * `$actorUserId` and the subject are both required and are not assumed
     * equal. They are the same person on this route, and they will not be on
     * Phase 4's HR route — an audit table that inferred the actor from the
     * subject would be useless for exactly the reads worth auditing.
     *
     * @param 'read'|'download'|'print'|'export' $accessKind
     */
    public function recordAccess(
        OnboardingDocumentRef $document,
        int $actorUserId,
        string $accessKind,
        string $permissionCode,
        ?string $correlationId,
        ?string $ipAddress,
        ?string $userAgent,
    ): void;
}
