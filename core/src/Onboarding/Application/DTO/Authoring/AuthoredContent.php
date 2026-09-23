<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Authoring;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\Service\FormVersionPublishGuard;
use TriCab\Core\Onboarding\Domain\Service\PolicyBlockRules;
use TriCab\Core\Onboarding\Domain\Service\TrainingAuthoringRules;

/**
 * The body of one content version as its author sees it — **answer key
 * included** (4.4).
 *
 * One class for the three item types rather than three, because every caller
 * handles them the same way — parse, store, show, check before publishing — and
 * only the fields differ. The fields a type does not use stay empty.
 *
 * ## This is the authored projection, and only the author gets it
 *
 * {@see toArray()} emits `correctOptionKey` and `correctReason`. It is served
 * on `/api/v1/admin/onboarding/content/*` alone, gated on
 * `ONBOARDING.CONTENT.MANAGE`. A trainee's view of the same question is built by
 * {@see \TriCab\Core\Onboarding\Domain\Service\QuestionRedaction} from the
 * stored rows, never from this object, so there is no call site that could
 * pass one to the other. `OnboardingContentEndpointsTest` holds both ends.
 */
final readonly class AuthoredContent
{
    public const TYPE_TRAINING = 'training';
    public const TYPE_POLICY = 'policy';
    public const TYPE_FORM = 'form';
    public const TYPES = [self::TYPE_TRAINING, self::TYPE_POLICY, self::TYPE_FORM];

    /**
     * @param list<array{id: string, title: string}> $slides
     * @param list<array{publicId?: string, prompt: string, options: list<array{key: string, label: string}>, correctOptionKey: string, correctReason: ?string}> $questions
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $fields
     * @param array<string, int> $classifications field path => data class
     */
    private function __construct(
        public string $itemType,
        public array $slides = [],
        public ?int $passMarkPercent = null,
        public array $questions = [],
        public array $blocks = [],
        public ?string $declaration = null,
        public ?string $effectiveFrom = null,
        public array $fields = [],
        public array $classifications = [],
        /** Policies only: as printed on the source document, e.g. HR-POL-034-V000. */
        public ?string $documentReference = null,
        public ?string $issuedLabel = null,
        public ?string $reviewDueLabel = null,
        /**
         * Policies only. False: the policy is read, not accepted, and "Mark as
         * read" completes it without an acknowledgement row. True for every
         * other item type, which has no such choice.
         */
        public bool $requiresAcknowledgement = true,
    ) {
    }

    /** What a new item's first draft holds. */
    public static function empty(string $itemType): self
    {
        return new self(
            self::assertType($itemType),
            passMarkPercent: $itemType === self::TYPE_TRAINING ? TrainingAuthoringRules::DEFAULT_PASS_MARK : null,
        );
    }

    /**
     * Parses and validates what an author sent. Every rule lives in the domain's
     * `*Rules` classes; this only picks the fields for the type.
     *
     * @param array<string, mixed> $body
     *
     * @throws InvalidOnboardingValue
     */
    public static function fromInput(string $itemType, array $body, ?string $accessGroup): self
    {
        return match (self::assertType($itemType)) {
            self::TYPE_TRAINING => new self(
                self::TYPE_TRAINING,
                slides: TrainingAuthoringRules::slides($body['slides'] ?? []),
                passMarkPercent: TrainingAuthoringRules::passMark($body['passMarkPercent'] ?? null),
                questions: TrainingAuthoringRules::questions($body['questions'] ?? []),
            ),
            self::TYPE_POLICY => new self(
                self::TYPE_POLICY,
                blocks: PolicyBlockRules::normalise($body['blocks'] ?? []),
                declaration: PolicyBlockRules::declaration(self::optionalString($body, 'declaration')),
                effectiveFrom: self::date(self::optionalString($body, 'effectiveFrom'), 'effective date'),
                documentReference: PolicyBlockRules::label(self::optionalString($body, 'documentReference'), 'document reference', PolicyBlockRules::MAX_REFERENCE_LENGTH),
                issuedLabel: PolicyBlockRules::label(self::optionalString($body, 'issuedLabel'), 'issued', PolicyBlockRules::MAX_LABEL_LENGTH),
                reviewDueLabel: PolicyBlockRules::label(self::optionalString($body, 'reviewDueLabel'), 'review due', PolicyBlockRules::MAX_LABEL_LENGTH),
                requiresAcknowledgement: self::flag($body, 'requiresAcknowledgement', true),
            ),
            default => self::form($body, $accessGroup ?? FormVersionPublishGuard::ACCESS_GROUP_HR),
        };
    }

    /**
     * Rebuilds content from what is stored. Trusted — it was validated on the
     * way in — so this does not re-run the rules, which would make a rule
     * tightened later lock authors out of reading what they wrote before it.
     *
     * @param list<array{id: string, title: string}> $slides
     * @param list<array{publicId?: string, prompt: string, options: list<array{key: string, label: string}>, correctOptionKey: string, correctReason: ?string}> $questions
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $fields
     * @param array<string, int> $classifications
     */
    public static function fromStorage(
        string $itemType,
        array $slides = [],
        ?int $passMarkPercent = null,
        array $questions = [],
        array $blocks = [],
        ?string $declaration = null,
        ?string $effectiveFrom = null,
        array $fields = [],
        array $classifications = [],
        ?string $documentReference = null,
        ?string $issuedLabel = null,
        ?string $reviewDueLabel = null,
        bool $requiresAcknowledgement = true,
    ): self {
        return new self(
            self::assertType($itemType),
            $slides,
            $passMarkPercent,
            $questions,
            $blocks,
            $declaration,
            $effectiveFrom,
            $fields,
            $classifications,
            $documentReference,
            $issuedLabel,
            $reviewDueLabel,
            $requiresAcknowledgement,
        );
    }

    /**
     * What stops this version being published, in words an author can act on.
     * Empty when it is ready.
     *
     * @return list<string>
     */
    public function publishProblems(?string $accessGroup): array
    {
        $problems = [];

        if ($this->itemType === self::TYPE_TRAINING) {
            if ($this->slides === []) {
                $problems[] = 'Add at least one slide.';
            }
            // Passing the assessment is the only way a module completes. A
            // module without questions would sit in everybody's list forever
            // — the source refused the same thing as MODULE_HAS_NO_COMPLETION_PATH.
            if ($this->questions === []) {
                $problems[] = 'Add at least one question: passing the assessment is how a module is completed.';
            }
            if ($this->passMarkPercent === null) {
                $problems[] = 'Set a pass mark.';
            }
        } elseif ($this->itemType === self::TYPE_POLICY) {
            if ($this->blocks === []) {
                $problems[] = 'Add the policy’s wording.';
            }
        } else {
            if ($this->fields === []) {
                $problems[] = 'Add at least one field.';
            }
            $unclassified = FormVersionPublishGuard::unclassifiedFields(
                array_map(static fn (array $field): string => is_string($field['path'] ?? null) ? $field['path'] : '', $this->fields),
                array_map(strval(...), array_keys($this->classifications)),
            );
            if ($unclassified !== [] && FormVersionPublishGuard::requiresClassification($accessGroup ?? FormVersionPublishGuard::ACCESS_GROUP_HR)) {
                $problems[] = 'Classify every field: ' . implode(', ', $unclassified) . '.';
            }
        }

        return $problems;
    }

    /**
     * Counts for the audit row. Never the content itself.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return match ($this->itemType) {
            self::TYPE_TRAINING => ['slideCount' => count($this->slides), 'questionCount' => count($this->questions)],
            self::TYPE_POLICY => ['blockCount' => count($this->blocks)],
            default => ['fieldCount' => count($this->fields)],
        };
    }

    /**
     * Same content, ignoring question public ids (which are reissued on every
     * save). A save that changes nothing writes nothing — an UPDATE that changes
     * nothing still moves the row's token and would invalidate a co-author's.
     */
    public function sameAs(self $other): bool
    {
        return $this->comparable() === $other->comparable();
    }

    /**
     * The authored projection. Includes the answer key — see the class comment.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->itemType) {
            self::TYPE_TRAINING => [
                'slides' => $this->slides,
                'passMarkPercent' => $this->passMarkPercent,
                'questions' => array_map(static fn (array $question): array => [
                    'publicId' => $question['publicId'] ?? null,
                    'prompt' => $question['prompt'],
                    'options' => $question['options'],
                    'correctOptionKey' => $question['correctOptionKey'],
                    'correctReason' => $question['correctReason'],
                ], $this->questions),
            ],
            self::TYPE_POLICY => [
                'blocks' => $this->blocks,
                'declaration' => $this->declaration,
                'effectiveFrom' => $this->effectiveFrom,
                'documentReference' => $this->documentReference,
                'issuedLabel' => $this->issuedLabel,
                'reviewDueLabel' => $this->reviewDueLabel,
                'requiresAcknowledgement' => $this->requiresAcknowledgement,
            ],
            default => [
                'fields' => array_map(fn (array $field): array => $field + [
                    'dataClass' => $this->classifications[is_string($field['path'] ?? null) ? $field['path'] : ''] ?? null,
                ], $this->fields),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function comparable(): array
    {
        $content = $this->toArray();
        if (isset($content['questions']) && is_array($content['questions'])) {
            $content['questions'] = array_map(static function (mixed $question): mixed {
                if (is_array($question)) {
                    unset($question['publicId']);
                }

                return $question;
            }, $content['questions']);
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function form(array $body, string $accessGroup): self
    {
        $schema = FormSchemaRules::normalise($body['fields'] ?? [], $accessGroup);

        return new self(self::TYPE_FORM, fields: $schema['fields'], classifications: $schema['classifications']);
    }

    private static function assertType(string $itemType): string
    {
        if (!in_array($itemType, self::TYPES, true)) {
            throw InvalidOnboardingValue::forOneOf('item type', $itemType, self::TYPES);
        }

        return $itemType;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalString(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw InvalidOnboardingValue::for($field, '(not text)');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function flag(array $body, string $field, bool $default): bool
    {
        $value = $body[$field] ?? $default;
        if (!is_bool($value)) {
            throw InvalidOnboardingValue::for($field, '(not true or false)');
        }

        return $value;
    }

    private static function date(?string $value, string $what): ?string
    {
        $trimmed = $value === null ? '' : trim($value);
        if ($trimmed === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed);
        if ($parsed === false || $parsed->format('Y-m-d') !== $trimmed) {
            throw InvalidOnboardingValue::for($what, $trimmed);
        }

        return $trimmed;
    }
}
