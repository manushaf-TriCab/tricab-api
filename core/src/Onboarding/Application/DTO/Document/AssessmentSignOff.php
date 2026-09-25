<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Document;

/**
 * What HR adds to a WHSE assessment sheet when signing it off (5.4): the
 * supervisor's or manager's feedback, who received it for HR, and when.
 */
final readonly class AssessmentSignOff
{
    public function __construct(
        public string $feedback,
        public string $receivedBy,
        public string $reviewedAtUtc,
    ) {
    }
}
