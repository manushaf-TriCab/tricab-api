<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\OnboardingLink;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;

/**
 * Where an onboarding person's link to a payroll employee, and the proposals
 * for it, are kept (`[onboarding].[TraineeProfiles].[EmployeeCode]` and
 * `[hr].[OnboardingLinkProposals]`).
 *
 * @phpstan-type Starter array{profileId: int, userId: int, displayName: string, startDate: ?string}
 * @phpstan-type Candidate array{code: string, userId: ?int, displayName: ?string, firstName: ?string, lastName: ?string, dateHired: ?string}
 */
interface OnboardingLinkRepository
{
    /** False until the schema script has been applied; nothing else is called then. */
    public function available(): bool;

    /**
     * Onboarding people with no employee code.
     *
     * @return list<array{profileId: int, userId: int, displayName: string, startDate: ?string}>
     */
    public function unlinkedStarters(): array;

    /**
     * Current employees no onboarding person is linked to.
     *
     * @return list<array{code: string, userId: ?int, displayName: ?string, firstName: ?string, lastName: ?string, dateHired: ?string}>
     */
    public function linkableEmployees(): array;

    /**
     * Pairs that already have a proposal, whatever became of it.
     *
     * @return array<string, true> keyed "profileId|employeeCode"
     */
    public function proposedPairs(): array;

    /**
     * @param list<array{profileId: int, employeeCode: string, reason: string}> $proposals
     *
     * @return int how many were stored (a pair that raced in is skipped)
     */
    public function addProposals(array $proposals): int;

    /**
     * @return array{profileId: int, userId: int, employeeCode: ?string, linkedAtUtc: ?string}|null
     */
    public function profile(string $personPublicId): ?array;

    /** @return string|null the onboarding person's public id */
    public function personPublicIdForEmployee(string $employeeCode): ?string;

    /**
     * Pending proposals for the person whose employee is still unlinked, oldest first.
     *
     * @return list<array{publicId: string, employeeCode: string, reason: string, proposedAtUtc: string}>
     */
    public function pendingProposals(int $profileId): array;

    /**
     * @return array{publicId: string, employeeCode: string, status: string}|null
     */
    public function proposal(int $profileId, string $proposalPublicId): ?array;

    /**
     * Link, mark the proposal confirmed, supersede the other pending proposals for this person and
     * for this employee, and append the audit event, in one transaction.
     *
     * @throws OnboardingLinkRefused when the person was linked, or the employee taken, meanwhile
     */
    public function confirm(int $profileId, string $proposalPublicId, string $employeeCode, ActorContext $actor, OnboardingAdminEvent $event): void;

    /**
     * @throws OnboardingLinkRefused when the proposal is no longer pending
     */
    public function dismiss(int $profileId, string $proposalPublicId, ActorContext $actor, OnboardingAdminEvent $event): void;

    /**
     * Clear the link and keep the pair from being proposed again.
     *
     * @throws OnboardingLinkRefused when the person is not linked
     */
    public function unlink(int $profileId, ActorContext $actor, OnboardingAdminEvent $event): void;
}
