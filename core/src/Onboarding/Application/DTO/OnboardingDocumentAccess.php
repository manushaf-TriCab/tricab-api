<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy;

/**
 * An authorized, audited document read, and how the bytes are to travel.
 *
 * Holding one of these means three things have already happened, in this order:
 * the document was resolved against the asking person's own id,
 * {@see DocumentDeliveryPolicy} decided how it may be delivered, and the read
 * was written to `audit.OnboardingDocumentAccessEvents`.
 *
 * The audit comes before the bytes on purpose. A read that failed halfway is
 * still a read — the actor was authorized, the reference was resolved, and
 * something was probably transferred — and auditing afterwards records only the
 * reads that finished, which is the wrong set and reliably the less interesting
 * one.
 */
final readonly class OnboardingDocumentAccess
{
    /**
     * @param DocumentDeliveryPolicy::STREAM|DocumentDeliveryPolicy::SIGNED_URL $mode
     */
    public function __construct(
        public OnboardingDocumentRef $document,
        public string $mode,
    ) {
    }

    public function mustBeStreamed(): bool
    {
        return $this->mode === DocumentDeliveryPolicy::STREAM;
    }
}
