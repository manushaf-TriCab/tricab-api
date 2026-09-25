<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * One question, as the server holds it — answer key included.
 *
 * This object is **never** serialised to a trainee. What a trainee receives is
 * built by {@see \TriCab\Core\Onboarding\Domain\Service\QuestionRedaction},
 * which has no path that emits {@see $correctOptionKey} for a question that has
 * not been answered. Work order invariant 1 is the reason the two are separate
 * classes rather than one class with a flag: a flag has a default, and a default
 * is what leaks the answer key on the day somebody adds a new call site.
 *
 * ## Why it is snapshotted
 *
 * An attempt stores the whole question set as it was put to the person, in
 * `onboarding.Attempts.QuestionSnapshotJson`, and grading reads that rather than
 * `onboarding.Questions`. Two reasons, and the second is the important one:
 *
 *  1. An author editing a question must not retrospectively change whether
 *     somebody passed.
 *  2. Grading against the snapshot means the answer key is fixed **before the
 *     answers arrive**. There is no window in which a request could influence
 *     which key it is graded against.
 *
 * Options are `[{key, label}]` and the key is what an answer names. Labels can
 * be reworded; keys cannot, because an acknowledgement of "option b" has to
 * still mean option b a year later.
 */
final class AssessmentQuestion
{
    /**
     * @param array<string, string> $options key to label, in presentation order
     */
    private function __construct(
        public readonly string $publicId,
        public readonly int $ordinal,
        public readonly string $prompt,
        private readonly array $options,
        private readonly string $correctOptionKey,
        private readonly ?string $correctReason,
    ) {
    }

    /**
     * @param array<string, string> $options
     *
     * @throws InvalidOnboardingValue when the correct key is not one of the options
     */
    public static function of(
        string $publicId,
        int $ordinal,
        string $prompt,
        array $options,
        string $correctOptionKey,
        ?string $correctReason = null,
    ): self {
        $key = trim($correctOptionKey);

        // An authored question whose answer key names no option can never be
        // passed, and would mark every trainee wrong without saying why. This is
        // the one place it can be caught cheaply, so it is caught here.
        if (!array_key_exists($key, $options)) {
            throw InvalidOnboardingValue::forOneOf(
                'correct option key for question ' . $publicId,
                $correctOptionKey,
                array_map(strval(...), array_keys($options)),
            );
        }

        return new self($publicId, $ordinal, $prompt, $options, $key, $correctReason);
    }

    /**
     * Reads a question out of an attempt's stored snapshot.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidOnboardingValue
     */
    public static function fromSnapshot(array $row): self
    {
        $options = [];
        $rawOptions = $row['options'] ?? [];

        if (is_array($rawOptions)) {
            foreach ($rawOptions as $key => $label) {
                // Tolerates both the map form written by toSnapshot() and the
                // [{key, label}] list form authored content uses.
                if (is_array($label) && isset($label['key'])) {
                    $optionKey = is_string($label['key']) ? $label['key'] : '';
                    $optionLabel = isset($label['label']) && is_string($label['label']) ? $label['label'] : '';
                } else {
                    $optionKey = (string) $key;
                    $optionLabel = is_string($label) ? $label : '';
                }

                if ($optionKey !== '') {
                    $options[$optionKey] = $optionLabel;
                }
            }
        }

        return self::of(
            is_string($row['publicId'] ?? null) ? $row['publicId'] : '',
            is_numeric($row['ordinal'] ?? null) ? (int) $row['ordinal'] : 0,
            is_string($row['prompt'] ?? null) ? $row['prompt'] : '',
            $options,
            is_string($row['correctOptionKey'] ?? null) ? $row['correctOptionKey'] : '',
            is_string($row['correctReason'] ?? null) ? $row['correctReason'] : null,
        );
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return $this->options;
    }

    public function hasOption(string $optionKey): bool
    {
        return array_key_exists($optionKey, $this->options);
    }

    public function isCorrect(?string $selectedOptionKey): bool
    {
        return $selectedOptionKey !== null && $selectedOptionKey === $this->correctOptionKey;
    }

    /**
     * The answer key.
     *
     * Named so that a reviewer of a diff can grep for it: every call site is
     * either the grader or the post-answer reveal, and a third one is a bug.
     */
    public function correctOptionKey(): string
    {
        return $this->correctOptionKey;
    }

    public function correctReason(): ?string
    {
        return $this->correctReason;
    }

    /**
     * The form stored in `Attempts.QuestionSnapshotJson` — server-side only.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'publicId' => $this->publicId,
            'ordinal' => $this->ordinal,
            'prompt' => $this->prompt,
            'options' => $this->options,
            'correctOptionKey' => $this->correctOptionKey,
            'correctReason' => $this->correctReason,
        ];
    }
}
