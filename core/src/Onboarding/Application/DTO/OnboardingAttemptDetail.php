<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

/**
 * One attempt at an assessment.
 *
 * {@see $questions} holds whichever projection the caller is entitled to, and
 * the two are never mixed in one object: an attempt in flight carries
 * {@see \TriCab\Core\Onboarding\Domain\Service\QuestionRedaction::forUnanswered()}
 * output, and a graded one carries the revealed form. The redaction service is
 * the only thing that builds either, so nothing here can construct a payload
 * with an answer key in it by accident.
 *
 * {@see $scorePercent} and {@see $isPass} are null until the attempt is
 * submitted, which is also what `CK_onboarding_Attempts_Submitted` requires of
 * the row.
 */
final readonly class OnboardingAttemptDetail
{
    /**
     * @param list<array<string, mixed>> $questions
     */
    public function __construct(
        public string $publicId,
        public int $attemptNumber,
        public string $startedAtUtc,
        public ?string $submittedAtUtc,
        public ?int $scorePercent,
        public ?bool $isPass,
        public array $questions = [],
    ) {
    }

    public function isSubmitted(): bool
    {
        return $this->submittedAtUtc !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'attemptNumber' => $this->attemptNumber,
            'startedAtUtc' => $this->startedAtUtc,
            'submittedAtUtc' => $this->submittedAtUtc,
            'scorePercent' => $this->scorePercent,
            'isPass' => $this->isPass,
            'questions' => $this->questions,
        ];
    }
}
