<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;

/**
 * What a trainee is allowed to see of a question, and when.
 *
 * Work order invariant 1, in one place: **an unanswered question never carries
 * its answer key**, and the key may be revealed only once the answer is recorded
 * and immutable.
 *
 * ## Two methods, not one method with a boolean
 *
 * {@see forUnanswered()} has no parameter that could reveal anything, so no
 * caller can get it wrong and no default can drift. {@see withRecordedAnswer()}
 * requires the recorded answer to be passed in, which means a caller that has
 * not got one — because the attempt has not been submitted — cannot call it at
 * all. The type system carries the rule rather than a code review.
 *
 * A single `toArray(bool $revealAnswers = false)` was the obvious alternative
 * and is exactly the shape that leaks: the day a list endpoint is added, the
 * default is whatever the author assumed, and a wrong assumption is invisible in
 * the diff.
 *
 * ## Why the reveal exists, and why only on a pass
 *
 * The source shows the correct option and its reason after an attempt is
 * graded, and that is worth keeping for somebody who has passed: it is the
 * difference between an assessment and a gate.
 *
 * Ordering alone is not enough, though. A re-sit is offered at once and draws
 * the same questions, so a key revealed after a *failed* attempt is a key for
 * the next one: submit blank, copy, re-sit, pass (found in Phase 8, owner
 * decision 25 Sep 2026). So a failed attempt gets {@see withRecordedOutcome()}
 * — what was chosen and whether it was right, never the key or its reason —
 * and only a passed one gets {@see withRecordedAnswer()}.
 *
 * Pure: no database, no request, no clock.
 */
final class QuestionRedaction
{
    /**
     * A question as it is put to somebody who has not answered it.
     *
     * @return array{publicId: string, ordinal: int, prompt: string, options: list<array{key: string, label: string}>}
     */
    public static function forUnanswered(AssessmentQuestion $question): array
    {
        return [
            'publicId' => $question->publicId,
            'ordinal' => $question->ordinal,
            'prompt' => $question->prompt,
            'options' => self::options($question),
        ];
    }

    /**
     * @param list<AssessmentQuestion> $questions
     *
     * @return list<array{publicId: string, ordinal: int, prompt: string, options: list<array{key: string, label: string}>}>
     */
    public static function forUnansweredSet(array $questions): array
    {
        return array_map(self::forUnanswered(...), $questions);
    }

    /**
     * A question with the answer that was recorded against it, plus the key.
     *
     * `$recordedOptionKey` is what the database holds, never what a request
     * carried. The distinction is the whole safeguard: a caller that passed the
     * request's answer through here would be revealing the key on the strength of
     * a claim the client made about itself.
     *
     * @return array<string, mixed>
     */
    public static function withRecordedAnswer(
        AssessmentQuestion $question,
        ?string $recordedOptionKey,
    ): array {
        return [
            'publicId' => $question->publicId,
            'ordinal' => $question->ordinal,
            'prompt' => $question->prompt,
            'options' => self::options($question),
            'selectedOptionKey' => $recordedOptionKey,
            'correctOptionKey' => $question->correctOptionKey(),
            'correctReason' => $question->correctReason(),
            'isCorrect' => $question->isCorrect($recordedOptionKey),
        ];
    }

    /**
     * @param list<AssessmentQuestion>   $questions
     * @param array<string, string|null> $recordedAnswers question public id to recorded option key
     *
     * @return list<array<string, mixed>>
     */
    public static function withRecordedAnswers(array $questions, array $recordedAnswers): array
    {
        return array_map(
            static fn (AssessmentQuestion $question): array => self::withRecordedAnswer(
                $question,
                $recordedAnswers[$question->publicId] ?? null,
            ),
            $questions,
        );
    }

    /**
     * A question from a failed attempt: the recorded answer and whether it was
     * right, and nothing that names the right one.
     *
     * @return array{publicId: string, ordinal: int, prompt: string, options: list<array{key: string, label: string}>, selectedOptionKey: ?string, isCorrect: bool}
     */
    public static function withRecordedOutcome(
        AssessmentQuestion $question,
        ?string $recordedOptionKey,
    ): array {
        return [
            'publicId' => $question->publicId,
            'ordinal' => $question->ordinal,
            'prompt' => $question->prompt,
            'options' => self::options($question),
            'selectedOptionKey' => $recordedOptionKey,
            'isCorrect' => $question->isCorrect($recordedOptionKey),
        ];
    }

    /**
     * @param list<AssessmentQuestion>   $questions
     * @param array<string, string|null> $recordedAnswers question public id to recorded option key
     *
     * @return list<array{publicId: string, ordinal: int, prompt: string, options: list<array{key: string, label: string}>, selectedOptionKey: ?string, isCorrect: bool}>
     */
    public static function withRecordedOutcomes(array $questions, array $recordedAnswers): array
    {
        return array_map(
            static fn (AssessmentQuestion $question): array => self::withRecordedOutcome(
                $question,
                $recordedAnswers[$question->publicId] ?? null,
            ),
            $questions,
        );
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private static function options(AssessmentQuestion $question): array
    {
        $options = [];

        foreach ($question->options() as $key => $label) {
            $options[] = ['key' => $key, 'label' => $label];
        }

        return $options;
    }
}
