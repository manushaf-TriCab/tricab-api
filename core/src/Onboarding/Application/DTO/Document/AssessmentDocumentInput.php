<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Document;

/**
 * Everything an assessment sheet needs (5.4): who sat it, the questions in the
 * order they were asked with the option chosen for each, and the outcome.
 *
 * Option keys, not wording: the sheet already prints the wording, and an
 * answer is marked by its position among the question's options.
 */
final readonly class AssessmentDocumentInput
{
    /**
     * @param list<array{optionKeys: list<string>, chosenKey: ?string}> $questions in the attempt's order
     */
    public function __construct(
        public string $moduleSlug,
        public string $moduleTitle,
        public array $questions,
        public int $scorePercent,
        public bool $passed,
        public int $passMarkPercent,
        public string $submittedAtUtc,
        public string $personName,
        public string $position,
        public string $department,
        public string $branch,
        public string $managerName,
        /** 5.4: set only on the copy made when HR signs the WHSE induction off. */
        public ?AssessmentSignOff $signOff = null,
    ) {
    }
}
