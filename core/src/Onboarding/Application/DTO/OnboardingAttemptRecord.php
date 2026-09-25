<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;

/**
 * An attempt as the **server** holds it, answer keys and all.
 *
 * The internal twin of {@see OnboardingAttemptDetail}. This one comes out of a
 * repository and never leaves the application layer; the outward one is built
 * from it by {@see \TriCab\Core\Onboarding\Domain\Service\QuestionRedaction} and
 * is the only one with a `toArray()`.
 *
 * That asymmetry is not an oversight. A serialisable object carrying
 * {@see AssessmentQuestion} instances is one `json_encode` away from publishing
 * the answer key, and the way to make that impossible is for the object to have
 * no method that produces a payload.
 *
 * {@see $recordedAnswers} is what the database holds, keyed by question public
 * id — never what a request claimed. It is empty until the attempt is submitted.
 */
final readonly class OnboardingAttemptRecord
{
    /**
     * @param list<AssessmentQuestion>   $questions      the attempt's own snapshot, in order
     * @param array<string, string|null> $recordedAnswers question public id to stored option key
     */
    public function __construct(
        public string $publicId,
        public int $attemptNumber,
        public string $startedAtUtc,
        public ?string $submittedAtUtc,
        public ?int $scorePercent,
        public ?bool $isPass,
        public array $questions,
        public array $recordedAnswers = [],
    ) {
    }

    public function isSubmitted(): bool
    {
        return $this->submittedAtUtc !== null;
    }
}
