<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\OnboardingLink;

use RuntimeException;

/** An onboarding-to-employee link action HR asked for and the data does not allow. */
final class OnboardingLinkRefused extends RuntimeException
{
    public const PERSON_NOT_FOUND = 'ONBOARDING_PERSON_NOT_FOUND';
    public const PROPOSAL_NOT_FOUND = 'HR_LINK_PROPOSAL_NOT_FOUND';
    public const PROPOSAL_DECIDED = 'HR_LINK_PROPOSAL_DECIDED';
    public const PERSON_ALREADY_LINKED = 'HR_LINK_PERSON_ALREADY_LINKED';
    public const EMPLOYEE_ALREADY_LINKED = 'HR_LINK_EMPLOYEE_ALREADY_LINKED';
    public const NOT_LINKED = 'HR_LINK_NOT_LINKED';

    private function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function personNotFound(): self
    {
        return new self(self::PERSON_NOT_FOUND, 404, 'No such onboarding person.');
    }

    public static function proposalNotFound(): self
    {
        return new self(self::PROPOSAL_NOT_FOUND, 404, 'No such match proposal for this person.');
    }

    public static function proposalDecided(): self
    {
        return new self(self::PROPOSAL_DECIDED, 409, 'This match has already been decided.');
    }

    public static function personAlreadyLinked(): self
    {
        return new self(self::PERSON_ALREADY_LINKED, 409, 'This person is already linked to an employee. Unlink them first.');
    }

    public static function employeeAlreadyLinked(): self
    {
        return new self(self::EMPLOYEE_ALREADY_LINKED, 409, 'That employee is already linked to another onboarding person.');
    }

    public static function notLinked(): self
    {
        return new self(self::NOT_LINKED, 409, 'This person is not linked to an employee.');
    }
}
