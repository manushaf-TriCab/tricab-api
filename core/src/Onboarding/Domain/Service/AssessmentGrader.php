<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use LogicException;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentGrade;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;

/**
 * Marks an attempt.
 *
 * Work order invariants 1 and 2 meet here: the answer key comes from the
 * attempt's own snapshot, and the score and pass flag are computed rather than
 * read off the request. A request body carrying `scorePercent` or `isPass` does
 * not need to be rejected field by field, because there is no parameter on this
 * class through which such a field could arrive — the only inputs are the
 * snapshot and a map of question id to chosen option key.
 *
 * ## The pass decision is exact; the score is rounded
 *
 * These are two different numbers and conflating them lets people pass who
 * should not. Seven correct out of nine is 77.7…%. Rounded for display that is
 * 78, and a module with a pass mark of 78 would then pass somebody who scored
 * below it. So the decision is integer arithmetic on the counts —
 * `correct × 100 ≥ passMark × total` — and never a comparison against the
 * rounded figure. The rounded figure exists so that a person is told "78%"
 * rather than "77.777777777778%".
 *
 * Rounding is half-up, which matches what a reader expects of a percentage and
 * is only ever generous in the display.
 *
 * ## No pass mark means no gate
 *
 * `TrainingModuleVersions.PassMarkPercent` is nullable and defaults to null. A
 * version with no pass mark is a read-and-confirm exercise rather than a test,
 * and an attempt against one passes: failing somebody against a threshold
 * nobody set would be inventing a rule and then enforcing it. The score is still
 * computed and recorded, so the author who forgot to set a pass mark can see
 * what people actually scored.
 *
 * ## An unanswered question is wrong
 *
 * Not skipped, not excluded from the denominator. Leaving a question blank would
 * otherwise raise the percentage of everything else, so the shortest route to a
 * pass would be to answer only the questions you are sure of.
 *
 * Pure: no database, no request, no clock.
 */
final class AssessmentGrader
{
    /**
     * @param list<AssessmentQuestion> $questions the attempt's snapshot, in order
     * @param array<string, string>    $answers   question public id to chosen option key
     *
     * @throws InvalidOnboardingValue when an answer names an option the question does not have
     */
    public static function grade(
        array $questions,
        array $answers,
        ?int $passMarkPercent,
    ): AssessmentGrade {
        if ($questions === []) {
            // Nothing can be scored out of nothing, and returning 0% or 100%
            // would both be lies. An assessment with no questions is a content
            // fault that must be caught before an attempt is ever started.
            throw new LogicException('An attempt cannot be graded against an empty question snapshot.');
        }

        $correctness = [];
        $correctCount = 0;

        foreach ($questions as $question) {
            $selected = $answers[$question->publicId] ?? null;

            if ($selected !== null && !$question->hasOption($selected)) {
                // Strict, following AssignmentStatus::fromString(): a client
                // sending an option that is not on the question has a bug, and
                // grading it as wrong would hide the bug behind a plausible
                // failing score that the person then has to argue with.
                throw InvalidOnboardingValue::forOneOf(
                    'answer to question ' . $question->publicId,
                    $selected,
                    array_map(strval(...), array_keys($question->options())),
                );
            }

            $isCorrect = $question->isCorrect($selected);
            $correctness[$question->publicId] = $isCorrect;

            if ($isCorrect) {
                ++$correctCount;
            }
        }

        $questionCount = count($questions);

        return new AssessmentGrade(
            correctCount: $correctCount,
            questionCount: $questionCount,
            scorePercent: self::displayScore($correctCount, $questionCount),
            isPass: self::isPass($correctCount, $questionCount, $passMarkPercent),
            passMarkPercent: $passMarkPercent,
            correctnessByQuestion: $correctness,
        );
    }

    /**
     * The decision. Integer arithmetic throughout, so no rounding can move it.
     */
    public static function isPass(int $correctCount, int $questionCount, ?int $passMarkPercent): bool
    {
        if ($passMarkPercent === null) {
            return true;
        }

        if ($questionCount <= 0) {
            return false;
        }

        return $correctCount * 100 >= $passMarkPercent * $questionCount;
    }

    /**
     * The figure a person is shown, 0–100. Never compared against a pass mark.
     */
    public static function displayScore(int $correctCount, int $questionCount): int
    {
        if ($questionCount <= 0) {
            return 0;
        }

        return (int) round($correctCount * 100 / $questionCount);
    }
}
