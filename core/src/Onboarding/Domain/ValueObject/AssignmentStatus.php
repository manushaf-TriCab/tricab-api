<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * One person's state on one allocated item — a training module, a policy, a form.
 *
 * Ported from `api/_lib/status.ts` in `tricab-training-vercel`, which is already
 * pure and is imported by both its handlers and its tests. Six statuses rather
 * than three because the pack HR issues is wider than a quiz that marks itself:
 * a signed policy or a payroll declaration is finished when a person has read it
 * and accepted it, and it can be sent back. So the machine carries work handed
 * in, work sent back, and a person who is stuck.
 *
 * Two properties are load-bearing and are the reason this is a value object
 * rather than a string column with a CHECK constraint:
 *
 *  1. Statuses are partitioned by **who may set them** — see
 *     {@see \TriCab\Core\Onboarding\Domain\Service\AssignmentStatusPolicy}.
 *     Nothing anywhere lets a person mark their own induction complete, which
 *     is the property that makes the record worth keeping at all.
 *  2. The sets are named once and read everywhere. `needsReview()` is the HR
 *     queue, the dashboard count and the reminder query. A status added later
 *     joins the right sets here and every caller follows.
 *
 * Labels are copied verbatim from the source. "Waiting on review" and "Changes
 * requested" are what people already recognise, and rewording them would be a
 * silent behaviour change. They are duplicated in
 * `tricab-web/src/components/onboarding/onboardingStatus.ts` for rendering;
 * this class is the authority.
 */
final class AssignmentStatus
{
    public const NOT_STARTED = 'not_started';
    public const IN_PROGRESS = 'in_progress';
    public const NEED_HELP = 'need_help';
    public const SUBMITTED = 'submitted';
    public const FIX_AND_RESUBMIT = 'fix_and_resubmit';
    public const COMPLETED = 'completed';

    /** Declaration order, which is roughly the order work moves through them. */
    public const ALL = [
        self::NOT_STARTED,
        self::IN_PROGRESS,
        self::NEED_HELP,
        self::SUBMITTED,
        self::FIX_AND_RESUBMIT,
        self::COMPLETED,
    ];

    /** Finished, and needing nobody's attention. */
    private const FINAL = [self::COMPLETED];

    /**
     * What lands in the reviewer queue.
     *
     * `need_help` is in here deliberately. Someone stuck is more urgent than a
     * submission — they cannot make progress at all — and putting both in one
     * queue means a reviewer who is only watching submissions cannot miss them.
     */
    private const NEEDS_REVIEW = [self::SUBMITTED, self::NEED_HELP];

    /** Still on the person's own plate. */
    private const TO_BE_WORKED_ON = [
        self::NOT_STARTED,
        self::IN_PROGRESS,
        self::NEED_HELP,
        self::FIX_AND_RESUBMIT,
    ];

    private const LABELS = [
        self::NOT_STARTED => 'Not started',
        self::IN_PROGRESS => 'In progress',
        self::NEED_HELP => 'Needs help',
        self::SUBMITTED => 'Waiting on review',
        self::FIX_AND_RESUBMIT => 'Changes requested',
        self::COMPLETED => 'Complete',
    ];

    /**
     * Reviewer queue order: most in need of a person first.
     *
     * Someone stuck outranks someone waiting, because they cannot proceed at
     * all while a submission is at least sitting still in a known state.
     */
    private const QUEUE_ORDER = [
        self::NEED_HELP => 1,
        self::SUBMITTED => 2,
        self::FIX_AND_RESUBMIT => 3,
        self::IN_PROGRESS => 4,
        self::NOT_STARTED => 5,
        self::COMPLETED => 6,
    ];

    /**
     * How far through an item each status counts as, for a progress figure.
     *
     * Counting completed items alone reports someone who has handed in eight of
     * nine packs as 0% on the ninth, which reads as "has not begun". Partial
     * credit describes where they actually are.
     *
     * These are presentation weights. Nobody is assessed on them, and no
     * decision may be taken from them — which is why they are integers per
     * hundred here rather than floats: a progress figure that is arithmetic on
     * floats invites someone to treat it as a measurement.
     */
    private const PROGRESS_WEIGHT_PERCENT = [
        self::NOT_STARTED => 0,
        self::IN_PROGRESS => 25,
        self::NEED_HELP => 25,
        self::FIX_AND_RESUBMIT => 40,
        self::SUBMITTED => 75,
        self::COMPLETED => 100,
    ];

    private function __construct(private readonly string $value)
    {
    }

    /**
     * Parses a status arriving over HTTP. Anything outside the vocabulary is an
     * error, because a client sending `definitely_done` has a bug that silence
     * would hide.
     */
    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALL, true)) {
            throw InvalidOnboardingValue::forOneOf('AssignmentStatus', $value, self::ALL);
        }

        return new self($normalized);
    }

    /**
     * Reads a status off a database row, tolerating a value that should not be
     * there.
     *
     * Deliberately forgiving where {@see fromString()} is strict. A row whose
     * status somebody hand-edited, or which predates a vocabulary change, must
     * not make a person's whole worklist unreadable — they would be locked out
     * of their induction by a data defect they cannot see or fix. It falls back
     * to `not_started`, the safest reading: work still to do, and nothing
     * claimed as finished on evidence that does not exist.
     */
    public static function fromStorage(mixed $value): self
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, self::ALL, true)) {
                return new self($normalized);
            }
        }

        return new self(self::NOT_STARTED);
    }

    public static function notStarted(): self
    {
        return new self(self::NOT_STARTED);
    }

    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS);
    }

    public static function needHelp(): self
    {
        return new self(self::NEED_HELP);
    }

    public static function submitted(): self
    {
        return new self(self::SUBMITTED);
    }

    public static function fixAndResubmit(): self
    {
        return new self(self::FIX_AND_RESUBMIT);
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    /** @return list<self> */
    public static function all(): array
    {
        return array_map(static fn (string $value): self => new self($value), self::ALL);
    }

    /** @return list<self> */
    public static function needsReviewSet(): array
    {
        return array_map(static fn (string $value): self => new self($value), self::NEEDS_REVIEW);
    }

    /** @return list<self> */
    public static function toBeWorkedOnSet(): array
    {
        return array_map(static fn (string $value): self => new self($value), self::TO_BE_WORKED_ON);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function label(): string
    {
        return self::LABELS[$this->value];
    }

    public function isFinal(): bool
    {
        return in_array($this->value, self::FINAL, true);
    }

    public function needsReview(): bool
    {
        return in_array($this->value, self::NEEDS_REVIEW, true);
    }

    public function isToBeWorkedOn(): bool
    {
        return in_array($this->value, self::TO_BE_WORKED_ON, true);
    }

    public function queueOrder(): int
    {
        return self::QUEUE_ORDER[$this->value];
    }

    /** Presentation weight, 0–100. Not a measurement of anything. */
    public function progressWeightPercent(): int
    {
        return self::PROGRESS_WEIGHT_PERCENT[$this->value];
    }
}
