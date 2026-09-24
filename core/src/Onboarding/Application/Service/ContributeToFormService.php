<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\Service\FormSubmissionRules;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;

/**
 * HR or the starter's named manager fills their part of a form (5.4) — the
 * induction checklist's items and sign-off, the vehicle inspection.
 *
 * Owner decision, 24 Sep 2026: HR fills any part; the manager recorded on the
 * starter's profile fills the manager parts, for their own starters only.
 *
 * Refused, and audited as refused:
 *  - somebody filling their own form's other parts — the rule
 *    {@see \TriCab\Core\Onboarding\Domain\Service\SelfReviewPolicy} states for
 *    review, restated here: one person on both sides of one record;
 *  - a manager who is not this starter's manager. That answers "not found",
 *    as any record that is not yours does (invariant 4);
 *  - a completed form: it has been accepted, and the acceptance refers to what
 *    was there.
 *
 * Answers are checked against the fields the role fills, and nothing else may
 * be written — {@see FormSubmissionRules::check()} with `filledBy`.
 */
final readonly class ContributeToFormService
{
    public const HR_PERMISSION = 'ONBOARDING.REVIEW';
    public const MANAGER_PERMISSION = 'ONBOARDING.SELF.READ';

    public function __construct(
        private OnboardingFormContributionRepository $contributions,
        private OnboardingAdminAuditLog $audit,
    ) {
    }

    /**
     * What HR sees to fill its part: the form, and every part filled so far.
     *
     * @return array<string, mixed>
     */
    public function viewAsHr(string $assignmentPublicId): array
    {
        $form = $this->contributions->findContext(strtolower(trim($assignmentPublicId)))
            ?? throw OnboardingAssignmentNotFound::forThisPerson();

        return $this->view($form, FormSchemaRules::FILLED_BY_HR);
    }

    /**
     * What the named manager sees; anybody else is told it does not exist.
     *
     * @return array<string, mixed>
     */
    public function viewAsManager(ActorContext $actor, string $assignmentPublicId): array
    {
        $form = $this->contributions->findContext(strtolower(trim($assignmentPublicId)));
        if ($form === null || $form->managerUserId !== $actor->userId) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        return $this->view($form, FormSchemaRules::FILLED_BY_MANAGER);
    }

    /**
     * The manager's own list: forms of their starters with a manager part.
     *
     * @return list<array<string, mixed>>
     */
    public function formsForManager(ActorContext $actor): array
    {
        return array_map(static fn (FormContributionContext $form): array => [
            'assignmentPublicId' => $form->assignmentPublicId,
            'title' => $form->title,
            'status' => $form->status->toString(),
            'statusLabel' => $form->status->label(),
        ], $this->contributions->formsForManager($actor->userId));
    }

    /** @return array<string, mixed> */
    private function view(FormContributionContext $form, string $role): array
    {
        return [
            'assignmentPublicId' => $form->assignmentPublicId,
            'title' => $form->title,
            'status' => $form->status->toString(),
            'statusLabel' => $form->status->label(),
            'role' => $role,
            'editable' => !$form->status->isFinal(),
            'schema' => $form->schema,
            'contributions' => (object) array_map(static fn (array $a): object => (object) $a, $this->contributions->latest($form->assignmentId)),
        ];
    }

    /**
     * @param array<string, mixed> $answers nested, as a form sends them
     */
    public function contributeAsHr(ActorContext $actor, string $assignmentPublicId, array $answers): FormContributionContext
    {
        $form = $this->contributions->findContext(strtolower(trim($assignmentPublicId)))
            ?? throw OnboardingAssignmentNotFound::forThisPerson();

        return $this->contribute($actor, $form, FormSchemaRules::FILLED_BY_HR, $answers, self::HR_PERMISSION);
    }

    /**
     * @param array<string, mixed> $answers
     */
    public function contributeAsManager(ActorContext $actor, string $assignmentPublicId, array $answers): FormContributionContext
    {
        $form = $this->contributions->findContext(strtolower(trim($assignmentPublicId)));
        if ($form === null || $form->managerUserId !== $actor->userId) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        return $this->contribute($actor, $form, FormSchemaRules::FILLED_BY_MANAGER, $answers, self::MANAGER_PERMISSION);
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function contribute(ActorContext $actor, FormContributionContext $form, string $role, array $answers, string $permission): FormContributionContext
    {
        try {
            if ($actor->userId === $form->subjectUserId) {
                throw SelfReviewNotPermitted::contribution(UserId::fromInt($actor->userId));
            }
            if ($form->status->isFinal()) {
                throw OnboardingWriteConflict::alreadyRecorded('completed form');
            }
            $flat = FormPayloadSplitter::flatten($answers);
            FormSubmissionRules::check($form->schema, $flat, $role);
        } catch (SelfReviewNotPermitted | OnboardingWriteConflict | InvalidOnboardingValue $refusal) {
            $this->audit->append($actor, OnboardingAdminEvent::refused(
                AdminEventType::FORM_CONTRIBUTION_REFUSED,
                $form->subjectUserId,
                $form->assignmentPublicId,
                sprintf('Contribution to "%s" as %s refused: %s', $form->title, $role, $refusal->getMessage()),
                ['role' => $role],
                $permission,
            ));

            throw $refusal;
        }

        $this->contributions->append($form, $role, $actor->userId, $flat, OnboardingAdminEvent::succeeded(
            AdminEventType::FORM_CONTRIBUTED,
            $form->subjectUserId,
            $form->assignmentPublicId,
            sprintf('Filled the %s part of "%s".', $role === FormSchemaRules::FILLED_BY_HR ? 'HR' : 'manager', $form->title),
            // Field names, never values (invariant 13).
            ['role' => $role, 'changedFields' => array_map('strval', array_keys($flat))],
            $permission,
        ), $actor);

        return $form;
    }

    /**
     * Required HR and manager fields still empty, by label — what stops a
     * reviewer completing the form.
     *
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $latest role => path => value
     * @return list<string>
     */
    public static function missingContributions(array $schema, array $latest): array
    {
        $missing = [];
        foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field) {
            $role = FormSchemaRules::filledBy($field);
            if ($role === FormSchemaRules::FILLED_BY_STARTER || !is_array($field) || ($field['required'] ?? false) !== true) {
                continue;
            }
            $value = $latest[$role][(string) ($field['path'] ?? '')] ?? null;
            $empty = $value === null || (is_string($value) && trim($value) === '') || (($field['type'] ?? '') === 'checkbox' && $value !== true);
            if ($empty) {
                $missing[] = is_string($field['label'] ?? null) ? $field['label'] : (string) ($field['path'] ?? '');
            }
        }

        return $missing;
    }
}
