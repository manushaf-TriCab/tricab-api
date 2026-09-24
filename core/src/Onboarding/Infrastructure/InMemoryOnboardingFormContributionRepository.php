<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;

final class InMemoryOnboardingFormContributionRepository implements OnboardingFormContributionRepository
{
    /** @var array<string, FormContributionContext> */
    public array $forms = [];

    /** @var list<array{assignmentId: int, role: string, contributorUserId: int, answers: array<string, mixed>}> */
    public array $rows = [];

    public function __construct(private readonly ?OnboardingAdminAuditLog $audit = null)
    {
    }

    public function withForm(FormContributionContext $form): void
    {
        $this->forms[strtolower($form->assignmentPublicId)] = $form;
    }

    public function findContext(string $assignmentPublicId): ?FormContributionContext
    {
        return $this->forms[strtolower($assignmentPublicId)] ?? null;
    }

    public function latest(int $assignmentId): array
    {
        $latest = [];
        foreach ($this->rows as $row) {
            if ($row['assignmentId'] === $assignmentId) {
                $latest[$row['role']] = $row['answers'];
            }
        }

        return $latest;
    }

    public function append(FormContributionContext $form, string $role, int $contributorUserId, array $flatAnswers, OnboardingAdminEvent $event, ActorContext $actor): void
    {
        $this->rows[] = ['assignmentId' => $form->assignmentId, 'role' => $role, 'contributorUserId' => $contributorUserId, 'answers' => $flatAnswers];
        $this->audit?->append($actor, $event);
    }

    public function formsForManager(int $managerUserId): array
    {
        return array_values(array_filter($this->forms, static function (FormContributionContext $form) use ($managerUserId): bool {
            if ($form->managerUserId !== $managerUserId) {
                return false;
            }
            foreach (is_array($form->schema['fields'] ?? null) ? $form->schema['fields'] : [] as $field) {
                if (FormSchemaRules::filledBy($field) === FormSchemaRules::FILLED_BY_MANAGER) {
                    return true;
                }
            }

            return false;
        }));
    }
}
