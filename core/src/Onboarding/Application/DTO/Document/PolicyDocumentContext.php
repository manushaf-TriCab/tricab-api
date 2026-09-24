<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Document;

use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;

/** A signed policy acknowledgement and the policy version it binds to, as stored. */
final readonly class PolicyDocumentContext
{
    public function __construct(
        public int $assignmentId,
        public int $userId,
        public string $acknowledgementPublicId,
        public string $policySlug,
        public string $policyTitle,
        public string $documentReference,
        public string $acknowledgedAtUtc,
        public PolicyDeclaration $declaration,
    ) {
    }
}
