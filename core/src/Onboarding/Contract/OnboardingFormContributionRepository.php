<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;

/**
 * The parts of a form HR or the named manager fill (5.4), in
 * `onboarding.FormContributions`: append-only, one row per save, the latest
 * per role being what the form shows. Every value is ordinary HR data (C2) —
 * only an `hr` form may have such parts.
 */
interface OnboardingFormContributionRepository
{
    /** The live form assignment, or null when there is none by that id. */
    public function findContext(string $assignmentPublicId): ?FormContributionContext;

    /**
     * The latest answers per role, flat dotted paths.
     *
     * @return array<string, array<string, mixed>> role => path => value
     */
    public function latest(int $assignmentId): array;

    /**
     * Appends a contribution and its audit row, in one transaction.
     *
     * @param array<string, mixed> $flatAnswers
     */
    public function append(FormContributionContext $form, string $role, int $contributorUserId, array $flatAnswers, OnboardingAdminEvent $event, ActorContext $actor): void;

    /**
     * Assignments naming `$managerUserId` as the starter's manager whose form has
     * manager parts, for the manager's own list.
     *
     * @return list<FormContributionContext>
     */
    public function formsForManager(int $managerUserId): array;
}
