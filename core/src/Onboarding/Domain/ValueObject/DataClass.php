<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * How sensitive a piece of Onboarding data is.
 *
 * The six classes from `docs/onboarding-architecture.md` §5, mirrored by
 * `onboarding.RetentionClasses`. The numbers are the database's, so they are
 * part of the contract rather than an implementation detail — `FieldClassifications`
 * and `DocumentReferences` both store one.
 *
 * The line that matters runs between C2 and C3. Below it is data the module
 * works with all day: statuses, progress, a job title. Above it is a bank
 * account, a medical questionnaire, a passport number — data whose every read is
 * worth recording. {@see \TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy}
 * is where that line turns into different behaviour.
 */
final class DataClass
{
    public const CONTENT = 0;
    public const WORKFLOW = 1;
    public const ORDINARY_HR = 2;
    public const PAYROLL = 3;
    public const RESTRICTED_HR = 4;
    public const GENERATED_DOCUMENT = 5;

    /** @var list<int> */
    public const ALL = [
        self::CONTENT,
        self::WORKFLOW,
        self::ORDINARY_HR,
        self::PAYROLL,
        self::RESTRICTED_HR,
        self::GENERATED_DOCUMENT,
    ];

    /**
     * The classes held in `[onboarding_hr]` — the ones a reviewer of this code
     * should expect to find audited reads for.
     */
    private const RESTRICTED = [self::PAYROLL, self::RESTRICTED_HR];

    private const CODES = [
        self::CONTENT => 'C0',
        self::WORKFLOW => 'C1',
        self::ORDINARY_HR => 'C2',
        self::PAYROLL => 'C3',
        self::RESTRICTED_HR => 'C4',
        self::GENERATED_DOCUMENT => 'C5',
    ];

    private function __construct(private readonly int $value)
    {
    }

    public static function fromInt(int $value): self
    {
        if (!in_array($value, self::ALL, true)) {
            throw InvalidOnboardingValue::forOneOf(
                'DataClass',
                (string) $value,
                array_map(strval(...), self::ALL),
            );
        }

        return new self($value);
    }

    public static function content(): self
    {
        return new self(self::CONTENT);
    }

    public static function workflow(): self
    {
        return new self(self::WORKFLOW);
    }

    public static function ordinaryHr(): self
    {
        return new self(self::ORDINARY_HR);
    }

    public static function payroll(): self
    {
        return new self(self::PAYROLL);
    }

    public static function restrictedHr(): self
    {
        return new self(self::RESTRICTED_HR);
    }

    public static function generatedDocument(): self
    {
        return new self(self::GENERATED_DOCUMENT);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function code(): string
    {
        return self::CODES[$this->value];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Payroll-sensitive or restricted HR: the two classes that live in
     * `[onboarding_hr]` and whose reads are audited.
     *
     * `GENERATED_DOCUMENT` is deliberately not one of them. A document's class is
     * its content's class, so a payroll PDF is recorded as C3 and a policy
     * declaration as C1 — C5 on a stored row means nobody classified it, which
     * {@see \TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy} treats
     * as the most sensitive case rather than the least.
     */
    public function isRestricted(): bool
    {
        return in_array($this->value, self::RESTRICTED, true);
    }
}
