<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

/**
 * The outcome of one graded attempt, computed server-side.
 *
 * Work order invariant 2: the API never accepts an authoritative score or pass
 * field from a browser. This object is the only thing that may produce one, and
 * it is constructed by {@see \TriCab\Core\Onboarding\Domain\Service\AssessmentGrader}
 * from the attempt's own snapshot. There is no constructor a request can reach.
 *
 * {@see $scorePercent} is for display and for `Attempts.ScorePercent`.
 * {@see $isPass} is the decision, and it is NOT derived from `$scorePercent` —
 * see the grader for why rounding must not be in that path.
 */
final readonly class AssessmentGrade
{
    /**
     * @param array<string, bool> $correctnessByQuestion question public id to whether it was right
     */
    public function __construct(
        public int $correctCount,
        public int $questionCount,
        public int $scorePercent,
        public bool $isPass,
        public ?int $passMarkPercent,
        public array $correctnessByQuestion,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'correctCount' => $this->correctCount,
            'questionCount' => $this->questionCount,
            'scorePercent' => $this->scorePercent,
            'isPass' => $this->isPass,
            'passMarkPercent' => $this->passMarkPercent,
        ];
    }
}
