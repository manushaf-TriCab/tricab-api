<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use LogicException;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Service\AssessmentGrader;
use TriCab\Core\Onboarding\Domain\Service\QuestionRedaction;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;

/**
 * Phase 3.3 — server-side marking, and the answer key never leaving early.
 *
 * Work order invariants 1 and 2. The two are tested together because they are
 * two halves of one arrangement: the key is snapshotted onto the attempt before
 * the answers arrive, so grading cannot be influenced by the request, and the
 * key is only rendered once the answers are recorded.
 */
final class AssessmentGradingTest extends TestCase
{
    // ------------------------------------------------------------- redaction

    /**
     * The invariant with the sharpest consequence. Every path that builds a
     * question for somebody who has not answered it goes through this method,
     * and this method has no parameter that could reveal anything.
     */
    public function test_an_unanswered_question_carries_no_answer_key(): void
    {
        $projected = QuestionRedaction::forUnanswered(self::question());
        $encoded = json_encode($projected, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('correctOptionKey', $projected);
        self::assertArrayNotHasKey('correctReason', $projected);
        self::assertArrayNotHasKey('isCorrect', $projected);
        self::assertStringNotContainsString('because the guard is the last line', $encoded);
        self::assertSame(
            ['publicId', 'ordinal', 'prompt', 'options'],
            array_keys($projected),
            'A field added to the projection must be a deliberate decision.',
        );
    }

    public function test_the_options_a_trainee_sees_carry_keys_and_labels_only(): void
    {
        $projected = QuestionRedaction::forUnanswered(self::question());

        self::assertSame(
            [
                ['key' => 'a', 'label' => 'Stop the line'],
                ['key' => 'b', 'label' => 'Call the supervisor'],
                ['key' => 'c', 'label' => 'Carry on'],
            ],
            $projected['options'],
        );
    }

    /**
     * Allowed, and only afterwards. Somebody who has just failed a question
     * learns nothing from being told only that they failed, and knowing the
     * answer now cannot change what is already immutable.
     */
    public function test_the_answer_is_revealed_once_the_response_is_recorded(): void
    {
        $revealed = QuestionRedaction::withRecordedAnswer(self::question(), 'c');

        self::assertSame('c', $revealed['selectedOptionKey']);
        self::assertSame('b', $revealed['correctOptionKey']);
        self::assertFalse($revealed['isCorrect']);
        self::assertSame('because the guard is the last line', $revealed['correctReason']);
    }

    public function test_an_unanswered_question_in_a_graded_attempt_still_reveals_the_key(): void
    {
        $revealed = QuestionRedaction::withRecordedAnswers([self::question()], []);

        self::assertNull($revealed[0]['selectedOptionKey']);
        self::assertFalse($revealed[0]['isCorrect']);
        self::assertSame('b', $revealed[0]['correctOptionKey']);
    }

    // --------------------------------------------------------------- grading

    public function test_the_score_and_the_pass_are_computed_from_the_snapshot(): void
    {
        $grade = AssessmentGrader::grade(self::threeQuestions(), ['q1' => 'b', 'q2' => 'b', 'q3' => 'b'], 60);

        self::assertSame(3, $grade->correctCount);
        self::assertSame(3, $grade->questionCount);
        self::assertSame(100, $grade->scorePercent);
        self::assertTrue($grade->isPass);
    }

    /**
     * There is no score field to forge, because there is no score parameter.
     * This is what invariant 2 looks like when it is held by a signature rather
     * than by a validation rule.
     */
    public function test_grading_takes_only_chosen_options_so_a_forged_score_has_nowhere_to_go(): void
    {
        $reflection = new \ReflectionMethod(AssessmentGrader::class, 'grade');
        $parameters = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $reflection->getParameters(),
        );

        self::assertSame(['questions', 'answers', 'passMarkPercent'], $parameters);
    }

    /**
     * The rounding trap, written down because it is the one that costs money.
     * Seven of nine is 77.7…%, which displays as 78 and must not pass a mark of
     * 78.
     */
    public function test_the_pass_decision_never_uses_the_rounded_score(): void
    {
        self::assertSame(78, AssessmentGrader::displayScore(7, 9));
        self::assertFalse(AssessmentGrader::isPass(7, 9, 78), 'Rounded up to the mark is still below it.');
        self::assertTrue(AssessmentGrader::isPass(7, 9, 77));
    }

    public function test_exactly_the_pass_mark_passes(): void
    {
        self::assertTrue(AssessmentGrader::isPass(4, 5, 80));
        self::assertFalse(AssessmentGrader::isPass(3, 5, 80));
    }

    /**
     * A version with no pass mark is a read-and-confirm exercise. Failing
     * somebody against a threshold nobody set would be inventing a rule.
     */
    public function test_no_pass_mark_means_no_gate(): void
    {
        $grade = AssessmentGrader::grade(self::threeQuestions(), ['q1' => 'a'], null);

        self::assertTrue($grade->isPass);
        self::assertSame(0, $grade->scorePercent, 'The score is still recorded so the author can see it.');
    }

    /**
     * Not skipped, and not excluded from the denominator — otherwise the
     * shortest route to a pass is to answer only what you are sure of.
     */
    public function test_an_unanswered_question_is_wrong_rather_than_ignored(): void
    {
        $grade = AssessmentGrader::grade(self::threeQuestions(), ['q1' => 'b', 'q2' => 'b'], 100);

        self::assertSame(2, $grade->correctCount);
        self::assertSame(3, $grade->questionCount);
        self::assertSame(67, $grade->scorePercent);
        self::assertFalse($grade->isPass);
    }

    /**
     * The snapshot is the authority on what was asked. An id it does not contain
     * describes a question that is not in this attempt, and refusing would let
     * somebody's stale second tab lock them out of submitting.
     */
    public function test_an_answer_to_a_question_outside_the_snapshot_is_ignored(): void
    {
        $grade = AssessmentGrader::grade(
            self::threeQuestions(),
            ['q1' => 'b', 'q2' => 'b', 'q3' => 'b', 'q-from-another-module' => 'b'],
            100,
        );

        self::assertSame(3, $grade->correctCount);
        self::assertTrue($grade->isPass);
    }

    /**
     * Refused rather than marked wrong, because it is a client defect and a
     * plausible failing score is a defect the person then has to argue with.
     */
    public function test_an_option_the_question_does_not_have_is_refused(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessageMatches('/answer to question q1/');

        AssessmentGrader::grade(self::threeQuestions(), ['q1' => 'z'], 50);
    }

    public function test_an_authored_answer_key_naming_no_option_is_refused_at_construction(): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        AssessmentQuestion::of('q9', 1, 'Prompt', ['a' => 'One', 'b' => 'Two'], 'c');
    }

    public function test_an_empty_snapshot_cannot_be_graded(): void
    {
        $this->expectException(LogicException::class);

        AssessmentGrader::grade([], [], 50);
    }

    public function test_a_snapshot_round_trips_through_storage(): void
    {
        $restored = AssessmentQuestion::fromSnapshot(self::question()->toSnapshot());

        self::assertSame('b', $restored->correctOptionKey());
        self::assertSame(['a' => 'Stop the line', 'b' => 'Call the supervisor', 'c' => 'Carry on'], $restored->options());
        self::assertTrue($restored->isCorrect('b'));
    }

    private static function question(): AssessmentQuestion
    {
        return AssessmentQuestion::of(
            'q1',
            1,
            'A guard is missing from a machine. What do you do first?',
            ['a' => 'Stop the line', 'b' => 'Call the supervisor', 'c' => 'Carry on'],
            'b',
            'because the guard is the last line',
        );
    }

    /** @return list<AssessmentQuestion> */
    private static function threeQuestions(): array
    {
        return [
            self::question(),
            AssessmentQuestion::of('q2', 2, 'Second', ['a' => 'No', 'b' => 'Yes'], 'b'),
            AssessmentQuestion::of('q3', 3, 'Third', ['a' => 'No', 'b' => 'Yes'], 'b'),
        ];
    }
}
