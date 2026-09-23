<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;

/**
 * What an author may put in a training module version (4.4): its slide list,
 * its pass mark and its questions.
 *
 * Question rules follow the source's `parseModulePayload`: two to six options,
 * each with a label, unique keys, and exactly one correct answer. The source
 * marked the correct option with a `correct: true` flag on the option; here it is
 * a key named beside the options, which is the shape `onboarding.Questions`
 * stores ({@see \TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion}).
 * "Exactly one" is then a property of the shape rather than a count to check.
 *
 * Slide ids are author-chosen and kept, not generated, because progress is
 * recorded against them: renumbering slides in a new draft would make somebody's
 * recorded progress point at different slides. They are lower-case words joined
 * by hyphens so that they read as names in a progress record.
 *
 * Pure: no database, no request, no clock.
 */
final class TrainingAuthoringRules
{
    public const MAX_SLIDES = 200;
    public const MAX_QUESTIONS = 100;
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 6;
    public const MAX_SLIDE_TITLE_LENGTH = 240;
    public const MAX_PROMPT_LENGTH = 2000;
    public const MAX_OPTION_LABEL_LENGTH = 500;
    public const MAX_REASON_LENGTH = 2000;
    public const DEFAULT_PASS_MARK = 80;

    private const SLIDE_ID = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const MAX_SLIDE_ID_LENGTH = 80;
    private const OPTION_KEY = '/^[a-z0-9]{1,40}$/';

    /**
     * @return list<array{id: string, title: string}>
     *
     * @throws InvalidOnboardingValue
     */
    public static function slides(mixed $slides): array
    {
        if (!is_array($slides) || !array_is_list($slides)) {
            throw InvalidOnboardingValue::for('slides', 'not a list');
        }
        if (count($slides) > self::MAX_SLIDES) {
            throw InvalidOnboardingValue::for('slides', sprintf('more than %d slides', self::MAX_SLIDES));
        }

        $normalised = [];
        foreach ($slides as $index => $slide) {
            $where = 'slide ' . ($index + 1);
            if (!is_array($slide)) {
                throw InvalidOnboardingValue::for($where, 'not an object');
            }
            $id = is_string($slide['id'] ?? null) ? trim($slide['id']) : '';
            if ($id === '' || strlen($id) > self::MAX_SLIDE_ID_LENGTH || preg_match(self::SLIDE_ID, $id) !== 1) {
                throw InvalidOnboardingValue::for($where . ' id', $id === '' ? '(missing)' : $id . ' — use lower-case words joined by hyphens');
            }
            $title = is_string($slide['title'] ?? null) ? trim($slide['title']) : '';
            if ($title === '') {
                throw InvalidOnboardingValue::required($where . ' title', 'every slide needs one.');
            }
            if (mb_strlen($title) > self::MAX_SLIDE_TITLE_LENGTH) {
                throw InvalidOnboardingValue::for($where . ' title', sprintf('longer than %d characters', self::MAX_SLIDE_TITLE_LENGTH));
            }
            $normalised[] = ['id' => $id, 'title' => $title];
        }

        // The manifest's own reading is the authority on duplicates — the same
        // check a trainee's progress is validated against.
        PresentationManifest::fromArray($normalised);

        return $normalised;
    }

    /**
     * @throws InvalidOnboardingValue
     */
    public static function passMark(mixed $passMark): int
    {
        if (!is_int($passMark) || $passMark < 1 || $passMark > 100) {
            throw InvalidOnboardingValue::for('pass mark', is_scalar($passMark) ? (string) $passMark . ' — a whole number from 1 to 100' : '(missing)');
        }

        return $passMark;
    }

    /**
     * @return list<array{prompt: string, options: list<array{key: string, label: string}>, correctOptionKey: string, correctReason: ?string}>
     *
     * @throws InvalidOnboardingValue naming the question that is wrong
     */
    public static function questions(mixed $questions): array
    {
        if (!is_array($questions) || !array_is_list($questions)) {
            throw InvalidOnboardingValue::for('questions', 'not a list');
        }
        if (count($questions) > self::MAX_QUESTIONS) {
            throw InvalidOnboardingValue::for('questions', sprintf('more than %d questions', self::MAX_QUESTIONS));
        }

        $normalised = [];
        foreach ($questions as $index => $question) {
            $normalised[] = self::question($question, 'question ' . ($index + 1));
        }

        return $normalised;
    }

    /**
     * @return array{prompt: string, options: list<array{key: string, label: string}>, correctOptionKey: string, correctReason: ?string}
     */
    private static function question(mixed $question, string $where): array
    {
        if (!is_array($question)) {
            throw InvalidOnboardingValue::for($where, 'not an object');
        }
        $prompt = self::text($question['prompt'] ?? null, $where . ' wording', self::MAX_PROMPT_LENGTH);
        if ($prompt === null) {
            throw InvalidOnboardingValue::required($where . ' wording', 'every question needs one.');
        }

        $rawOptions = $question['options'] ?? null;
        if (!is_array($rawOptions) || !array_is_list($rawOptions)) {
            throw InvalidOnboardingValue::for($where . ' options', 'not a list');
        }
        if (count($rawOptions) < self::MIN_OPTIONS || count($rawOptions) > self::MAX_OPTIONS) {
            throw InvalidOnboardingValue::for($where . ' options', sprintf('%d options — a question needs %d to %d', count($rawOptions), self::MIN_OPTIONS, self::MAX_OPTIONS));
        }

        $options = [];
        $keys = [];
        foreach ($rawOptions as $optionIndex => $option) {
            $optionWhere = sprintf('%s option %d', $where, $optionIndex + 1);
            if (!is_array($option)) {
                throw InvalidOnboardingValue::for($optionWhere, 'not an object');
            }
            $key = is_string($option['key'] ?? null) ? trim($option['key']) : '';
            if (preg_match(self::OPTION_KEY, $key) !== 1) {
                throw InvalidOnboardingValue::for($optionWhere . ' key', $key === '' ? '(missing)' : $key);
            }
            if (in_array($key, $keys, true)) {
                throw InvalidOnboardingValue::for($optionWhere . ' key', $key . ' — used twice');
            }
            $label = self::text($option['label'] ?? null, $optionWhere, self::MAX_OPTION_LABEL_LENGTH);
            if ($label === null) {
                throw InvalidOnboardingValue::required($optionWhere, 'every option needs wording.');
            }
            $keys[] = $key;
            $options[] = ['key' => $key, 'label' => $label];
        }

        $correct = is_string($question['correctOptionKey'] ?? null) ? trim($question['correctOptionKey']) : '';
        if (!in_array($correct, $keys, true)) {
            throw InvalidOnboardingValue::required($where . ' correct answer', 'choose which option is right.');
        }

        return [
            'prompt' => $prompt,
            'options' => $options,
            'correctOptionKey' => $correct,
            'correctReason' => self::text($question['correctReason'] ?? null, $where . ' explanation', self::MAX_REASON_LENGTH),
        ];
    }

    private static function text(mixed $value, string $where, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for($where, '(not text)');
        }
        $trimmed = trim($value);
        if (mb_strlen($trimmed) > $max) {
            throw InvalidOnboardingValue::for($where, sprintf('longer than %d characters', $max));
        }

        return $trimmed === '' ? null : $trimmed;
    }
}
