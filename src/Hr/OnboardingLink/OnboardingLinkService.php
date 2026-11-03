<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\OnboardingLink;

use TriCab\Api\Hr\Employees\Directory\EmployeeDirectory;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;

/**
 * HR Employees phase 5: the link between an onboarding person and their payroll
 * employee. The sync proposes; this is where HR decides. Nothing links without a
 * person here confirming one named proposal, and every decision is an audit row
 * in `[audit].[OnboardingAdminEvents]`.
 *
 * Only base employee fields appear here (name, code, department, hire date),
 * never the restricted block.
 */
final class OnboardingLinkService
{
    /** The permission the audit rows record; the routes also need HR.EMPLOYEES.READ. */
    public const PERMISSION = 'ONBOARDING.PEOPLE.MANAGE';

    public function __construct(
        private readonly OnboardingLinkRepository $links,
        private readonly EmployeeDirectory $employees,
        private readonly OnboardingPeopleRepository $people,
    ) {
    }

    /**
     * @return array{personPublicId: string, linked: array{employee: array<string, mixed>|null, linkedAtUtc: string|null}|null, proposals: list<array{publicId: string, reason: string, proposedAtUtc: string, employee: array<string, mixed>}>}
     *
     * @throws OnboardingLinkRefused
     */
    public function forPerson(string $personPublicId): array
    {
        $personPublicId = strtolower(trim($personPublicId));
        $profile = $this->links->profile($personPublicId) ?? throw OnboardingLinkRefused::personNotFound();

        $linked = null;
        $proposals = [];
        if ($profile['employeeCode'] !== null) {
            $linked = [
                'employee' => $this->employeeBlock($profile['employeeCode']),
                'linkedAtUtc' => $profile['linkedAtUtc'],
            ];
        } else {
            foreach ($this->links->pendingProposals($profile['profileId']) as $proposal) {
                $employee = $this->employeeBlock($proposal['employeeCode']);
                if ($employee === null) {
                    continue;
                }
                $proposals[] = [
                    'publicId' => $proposal['publicId'],
                    'reason' => $proposal['reason'],
                    'proposedAtUtc' => $proposal['proposedAtUtc'],
                    'employee' => $employee,
                ];
            }
        }

        return ['personPublicId' => $personPublicId, 'linked' => $linked, 'proposals' => $proposals];
    }

    /**
     * @return array{personPublicId: string, linked: array{employee: array<string, mixed>|null, linkedAtUtc: string|null}|null, proposals: list<array{publicId: string, reason: string, proposedAtUtc: string, employee: array<string, mixed>}>}
     *
     * @throws OnboardingLinkRefused
     */
    public function confirm(ActorContext $actor, string $personPublicId, string $proposalPublicId): array
    {
        $personPublicId = strtolower(trim($personPublicId));
        $proposalPublicId = strtolower(trim($proposalPublicId));
        $profile = $this->links->profile($personPublicId) ?? throw OnboardingLinkRefused::personNotFound();
        if ($profile['employeeCode'] !== null) {
            throw OnboardingLinkRefused::personAlreadyLinked();
        }
        $proposal = $this->decidableProposal($profile['profileId'], $proposalPublicId);

        $this->links->confirm(
            $profile['profileId'],
            $proposalPublicId,
            $proposal['employeeCode'],
            $actor,
            OnboardingAdminEvent::succeeded(
                AdminEventType::PERSON_EMPLOYEE_LINKED,
                $profile['userId'],
                $personPublicId,
                'Linked the onboarding record to a payroll employee.',
                ['employeeCode' => $proposal['employeeCode']],
                self::PERMISSION,
            ),
        );

        return $this->forPerson($personPublicId);
    }

    /**
     * @return array{personPublicId: string, linked: array{employee: array<string, mixed>|null, linkedAtUtc: string|null}|null, proposals: list<array{publicId: string, reason: string, proposedAtUtc: string, employee: array<string, mixed>}>}
     *
     * @throws OnboardingLinkRefused
     */
    public function dismiss(ActorContext $actor, string $personPublicId, string $proposalPublicId): array
    {
        $personPublicId = strtolower(trim($personPublicId));
        $proposalPublicId = strtolower(trim($proposalPublicId));
        $profile = $this->links->profile($personPublicId) ?? throw OnboardingLinkRefused::personNotFound();
        $proposal = $this->decidableProposal($profile['profileId'], $proposalPublicId);

        $this->links->dismiss(
            $profile['profileId'],
            $proposalPublicId,
            $actor,
            OnboardingAdminEvent::succeeded(
                AdminEventType::PERSON_EMPLOYEE_LINK_DISMISSED,
                $profile['userId'],
                $personPublicId,
                'Dismissed a proposed payroll match.',
                ['employeeCode' => $proposal['employeeCode']],
                self::PERMISSION,
            ),
        );

        return $this->forPerson($personPublicId);
    }

    /**
     * @return array{personPublicId: string, linked: array{employee: array<string, mixed>|null, linkedAtUtc: string|null}|null, proposals: list<array{publicId: string, reason: string, proposedAtUtc: string, employee: array<string, mixed>}>}
     *
     * @throws OnboardingLinkRefused
     */
    public function unlink(ActorContext $actor, string $personPublicId): array
    {
        $personPublicId = strtolower(trim($personPublicId));
        $profile = $this->links->profile($personPublicId) ?? throw OnboardingLinkRefused::personNotFound();
        if ($profile['employeeCode'] === null) {
            throw OnboardingLinkRefused::notLinked();
        }

        $this->links->unlink(
            $profile['profileId'],
            $actor,
            OnboardingAdminEvent::succeeded(
                AdminEventType::PERSON_EMPLOYEE_UNLINKED,
                $profile['userId'],
                $personPublicId,
                'Removed the link to a payroll employee.',
                ['employeeCode' => $profile['employeeCode']],
                self::PERMISSION,
            ),
        );

        return $this->forPerson($personPublicId);
    }

    /**
     * The onboarding record of an employee and how far it has got: null when none is linked.
     *
     * @return array<string, mixed>|null
     */
    public function forEmployee(string $employeeCode): ?array
    {
        $personPublicId = $this->links->personPublicIdForEmployee($employeeCode);
        if ($personPublicId === null) {
            return null;
        }
        $profile = $this->links->profile($personPublicId);
        $detail = $this->people->personDetail($personPublicId);
        if ($profile === null || $detail === null) {
            return null;
        }
        $person = $detail->person->toArray();

        return [
            'personPublicId' => $personPublicId,
            'displayName' => $person['displayName'],
            'position' => $person['position'],
            'startDate' => $person['startDate'],
            'lastDay' => $person['lastDay'],
            'assignedCount' => $person['assignedCount'],
            'completedCount' => $person['completedCount'],
            'awaitingReviewCount' => $person['awaitingReviewCount'],
            'progressPercent' => $person['progressPercent'],
            'linkedAtUtc' => $profile['linkedAtUtc'],
        ];
    }

    /**
     * @return array{publicId: string, employeeCode: string, status: string}
     *
     * @throws OnboardingLinkRefused
     */
    private function decidableProposal(int $profileId, string $proposalPublicId): array
    {
        $proposal = $this->links->proposal($profileId, $proposalPublicId) ?? throw OnboardingLinkRefused::proposalNotFound();
        if ($proposal['status'] !== 'pending') {
            throw OnboardingLinkRefused::proposalDecided();
        }

        return $proposal;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function employeeBlock(string $code): ?array
    {
        $employee = $this->employees->find($code);
        if ($employee === null) {
            return null;
        }

        return [
            'code' => $employee['code'] ?? $code,
            'displayName' => $employee['displayName'] ?? null,
            'department' => $employee['department'] ?? null,
            'factory' => $employee['factory'] ?? null,
            'dateHired' => $employee['dateHired'] ?? null,
            'isCurrent' => $employee['isCurrent'] ?? null,
            'lastSeenInPayroll' => $employee['lastSeenInPayroll'] ?? null,
            // "In payroll since": the hire date payroll holds.
            'inPayrollSince' => $employee['dateHired'] ?? null,
        ];
    }
}
