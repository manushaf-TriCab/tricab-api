<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Contract\NprBranchDirectory;
use TriCab\Core\Npr\Contract\NprEventSink;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\Exception\NprValidationFailed;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprScreeningDecision;
use TriCab\Core\Npr\Domain\NprTransition;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Module;
use TriCab\Core\Shared\Time\Clock;

/**
 * The request's life after drafting (plan N2.2). Port of the transition
 * methods of the npr-portal's RequestService, to docs/ACCESS_AND_ROUTING.md:
 *
 *   draft -> awaiting_manager -> with_tsd -> awaiting_screening -> screening_completed
 *
 * Returns go to `returned_to_sales` (the Activity row and payload record the
 * stage they came from); a manager's rejection is `cancelled`.
 *
 * Every step: the caller must have a relationship to the record (otherwise it
 * is "not found": null), the version token must be current (else
 * {@see NprRequestConflict}), the status must allow the step (else
 * {@see NprWorkflowRefused}), and the repository then commits the change with
 * one Activity row. One {@see NprWorkflowEvent} per commit goes to the sink.
 *
 * Blocked, not defaulted: submission with no manager route, a self-endorsement
 * (interim policy), and request-screening / decide while no signatories are
 * nominated ({@see NprSigningPolicy}).
 */
final class NprWorkflowService
{
    private const STAMP = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly NprRequestRepository $requests,
        private readonly NprBranchDirectory $branches,
        private readonly NprAccessPolicy $policy,
        private readonly NprValidationService $validator,
        private readonly ManagerRoutingService $routing,
        private readonly NprPeople $people,
        private readonly NprSigningPolicy $signing,
        private readonly NprEventSink $events,
        private readonly Clock $clock,
    ) {
    }

    // ----- Sales -----

    /**
     * Sends the caller's own draft (or returned request) to its branch manager.
     * The draft stays saved when this refuses.
     *
     * @return NprRequestView|null null when it is not the caller's
     * @throws NprRequestNotEditable
     * @throws NprRequestConflict
     * @throws NprValidationFailed   Sales answers incomplete (422)
     * @throws NprWorkflowRefused    NO_MANAGER_ROUTE, SELF_ENDORSEMENT
     */
    public function submit(AuthenticatedUser $user, string $publicId, string $version): ?NprRequestView
    {
        $current = $this->requests->find($publicId);
        if ($current === null || !$this->policy->canReadOwn($user, $current)) {
            return null;
        }
        if (!$this->policy->canSubmit($user, $current)) {
            throw new NprRequestNotEditable($current->status->value);
        }
        self::assertVersion($current, $version);

        $check = $this->validator->validateSubmission($current->answers, $current->variants);
        if (!$check['valid']) {
            throw new NprValidationFailed('Form has validation errors. Please review all required fields.', $check['errors']);
        }

        $branch = $this->branches->branchForEmail($current->requesterEmail);
        if ($branch === NprBranchDirectory::UNASSIGNED) {
            $branch = $current->requesterBranch;
        }
        $route = $this->routing->resolve($branch);
        if ($route === null) {
            throw new NprWorkflowRefused(NprWorkflowRefused::NO_MANAGER_ROUTE, sprintf(
                "No confirmed Sales/Branch Manager route for branch '%s'. Your draft is saved; submission is held until management confirms the branch approver.",
                $branch,
            ));
        }
        if ($route->manager->is($current->requesterUserId)) {
            throw new NprWorkflowRefused(NprWorkflowRefused::SELF_ENDORSEMENT, 'You are the manager for your own branch, and a manager may not endorse their own request. Submission is held until another approver is routed.');
        }

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                'submitted_for_endorsement',
                'requester',
                self::actor($user),
                [RequestStatus::Draft, RequestStatus::ReturnedToSales],
                RequestStatus::AwaitingManager,
                sprintf('Submitted to Sales/Branch Manager (%s) for endorsement', $route->manager->name),
                assignManager: $route->manager,
                markSubmitted: true,
            ),
            $current->submittedAt !== null ? NprWorkflowEvent::SALES_RESUBMITTED : NprWorkflowEvent::SALES_SUBMITTED,
        );
    }

    // ----- Manager -----

    /** @return list<NprRequestView> requests assigned to the caller, newest first; empty without NPR.TEAM.ENDORSE */
    public function listForManager(AuthenticatedUser $user): array
    {
        if (!NprAccessPolicy::holds($user, Module::PERMISSION_TEAM_ENDORSE)) {
            return [];
        }

        return array_map(fn (NprRequest $request): NprRequestView => $this->view($user, $request), $this->requests->listForManager($user->userId));
    }

    /** A request assigned to the caller as manager, or null (someone else's manager, a draft, missing). */
    public function getForManager(AuthenticatedUser $user, string $publicId): ?NprRequestView
    {
        $request = $this->asManager($user, $publicId);

        return $request === null ? null : $this->view($user, $request);
    }

    /**
     * The manager signs the exact revision: declaration agreed, identity, time.
     * First endorsement is TSD's receipt; a repeat after a return keeps it.
     *
     * @throws InvalidNprInput       declaration not agreed
     * @throws NprWorkflowRefused    SELF_ENDORSEMENT, NOT_ALLOWED_IN_STATUS
     */
    public function endorse(AuthenticatedUser $user, string $publicId, string $version, mixed $declaration, ?string $comment): ?NprRequestView
    {
        $current = $this->asManager($user, $publicId);
        if ($current === null) {
            return null;
        }
        self::assertVersion($current, $version);
        self::assertStatus($current, RequestStatus::AwaitingManager);
        if ($current->isOwnedBy($user->userId)) {
            throw new NprWorkflowRefused(NprWorkflowRefused::SELF_ENDORSEMENT, 'A manager may not endorse their own request.');
        }
        if ($declaration !== true) {
            throw new InvalidNprInput('The endorsement declaration must be agreed (declaration: true).');
        }
        $note = self::optionalText($comment);

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                'manager_endorsed',
                'manager',
                self::actor($user),
                [RequestStatus::AwaitingManager],
                RequestStatus::WithTsd,
                sprintf('Endorsed revision %d by Manager %s. Transferred to TSD queue.', $current->revision, $user->displayName),
                markEndorsed: true,
                signRevision: true,
                endorsement: ['action' => 'endorsed', 'declaration' => true, 'comment' => $note],
            ),
            NprWorkflowEvent::MANAGER_ENDORSED,
            $current->tsdReceivedAt === null,
        );
    }

    /** @throws InvalidNprInput reason missing */
    public function managerReturn(AuthenticatedUser $user, string $publicId, string $version, mixed $reason): ?NprRequestView
    {
        return $this->managerExit($user, $publicId, $version, $reason, 'returned', RequestStatus::ReturnedToSales, 'manager_returned', NprWorkflowEvent::MANAGER_RETURNED, 'A return reason is required', 'Returned to Sales: %s');
    }

    /** @throws InvalidNprInput reason missing */
    public function managerReject(AuthenticatedUser $user, string $publicId, string $version, mixed $reason): ?NprRequestView
    {
        return $this->managerExit($user, $publicId, $version, $reason, 'rejected', RequestStatus::Cancelled, 'manager_rejected', NprWorkflowEvent::MANAGER_REJECTED, 'A rejection reason is required', 'Rejected by Manager: %s');
    }

    // ----- TSD -----

    /** Records receipt: whether the form is complete, with any notes. @throws InvalidNprInput */
    public function acknowledge(AuthenticatedUser $user, string $publicId, string $version, mixed $formComplete, ?string $notes): ?NprRequestView
    {
        $current = $this->asTsdWorker($user, $publicId);
        if ($current === null) {
            return null;
        }
        self::assertVersion($current, $version);
        self::assertStatus($current, RequestStatus::WithTsd);
        if (!is_bool($formComplete)) {
            throw new InvalidNprInput('formComplete is required: true or false.');
        }
        $note = self::optionalText($notes);
        $received = $current->tsdReceivedAt ?? $current->updatedAt;
        $summary = sprintf('TSD receipt acknowledged by %s. Form complete: %s', $user->displayName, $formComplete ? 'Yes' : 'No');

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                'tsd_acknowledged',
                'tsd',
                self::actor($user),
                [RequestStatus::WithTsd],
                null,
                $summary,
                markAcknowledged: true,
                answerPatch: [
                    'received_at' => ['state' => 'provided', 'value' => $received->format(self::STAMP)],
                    'received_by' => ['state' => 'provided', 'value' => $user->displayName],
                    'acknowledged_at' => ['state' => 'provided', 'value' => $this->stamp()],
                    'form_complete' => ['state' => 'provided', 'value' => $formComplete ? 'yes' : 'no'],
                    'receipt_notes' => $note !== null
                        ? ['state' => 'provided', 'value' => $note]
                        : ['state' => 'not_applicable', 'reason' => 'No receipt notes'],
                ],
            ),
            NprWorkflowEvent::TSD_ACKNOWLEDGED,
        );
    }

    /**
     * Names the TSD owner: an active user with NPR.TSD.WORK.
     *
     * @throws InvalidNprInput
     */
    public function assignOwner(AuthenticatedUser $user, string $publicId, string $version, mixed $ownerUserId, mixed $ownerEmail): ?NprRequestView
    {
        $current = $this->asTsdWorker($user, $publicId);
        if ($current === null) {
            return null;
        }
        self::assertVersion($current, $version);
        self::assertStatus($current, RequestStatus::WithTsd, RequestStatus::AwaitingScreening);
        $owner = $this->people->findActiveWith(
            is_int($ownerUserId) ? $ownerUserId : null,
            is_string($ownerEmail) ? $ownerEmail : null,
            Module::PERMISSION_TSD_WORK,
        );
        if ($owner === null) {
            throw new InvalidNprInput('The TSD owner must be an active user who can work TSD requests (ownerUserId or ownerEmail).');
        }
        $person = NprPeople::person($owner);

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                'tsd_assigned',
                'tsd',
                self::actor($user),
                [RequestStatus::WithTsd, RequestStatus::AwaitingScreening],
                null,
                sprintf('Assigned TSD owner to %s (%s)', $person->name, $person->email),
                assignTsdOwner: $person,
            ),
            NprWorkflowEvent::TSD_ASSIGNED,
        );
    }

    /** @throws InvalidNprInput reason missing */
    public function tsdReturn(AuthenticatedUser $user, string $publicId, string $version, mixed $reason): ?NprRequestView
    {
        $current = $this->asTsdWorker($user, $publicId);
        if ($current === null) {
            return null;
        }
        self::assertVersion($current, $version);
        self::assertStatus($current, RequestStatus::WithTsd);
        $text = self::requiredText($reason, 'A return reason is required');

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                'tsd_returned',
                'tsd',
                self::actor($user),
                [RequestStatus::WithTsd],
                RequestStatus::ReturnedToSales,
                sprintf('Returned to Sales by TSD (%s): %s', $user->displayName, $text),
                payload: ['stage' => 'tsd', 'reason' => $text],
                answerPatch: [
                    'returned_at' => ['state' => 'provided', 'value' => $this->stamp()],
                    'receipt_notes' => ['state' => 'provided', 'value' => $text],
                ],
            ),
            NprWorkflowEvent::TSD_RETURNED,
        );
    }

    /**
     * Saves TSD-owned answers (Sections 9 and 10) into the current revision.
     * Anything else, Sales answers above all, is refused, never merged.
     *
     * @param array<mixed> $answers field id => answer envelope
     * @throws InvalidNprInput
     * @throws NprValidationFailed
     */
    public function saveAssessment(AuthenticatedUser $user, string $publicId, string $version, array $answers): ?NprRequestView
    {
        $current = $this->asTsdWorker($user, $publicId);
        if ($current === null) {
            return null;
        }
        self::assertVersion($current, $version);
        self::assertStatus($current, RequestStatus::WithTsd, RequestStatus::AwaitingScreening);
        if ($answers === [] || array_is_list($answers)) {
            throw new InvalidNprInput('answers must be an object keyed by TSD field id.');
        }

        $foreign = [];
        $errors = [];
        $patch = [];
        foreach ($answers as $fieldId => $answer) {
            $definition = is_string($fieldId) ? $this->validator->getFieldDefinition($fieldId) : null;
            if ($definition === null || ($definition['owner'] ?? '') !== 'tsd' || ($definition['scope'] ?? '') !== 'request') {
                $foreign[] = (string) $fieldId;
                continue;
            }
            $found = $this->validator->validateAnswerEnvelope($definition, is_array($answer) ? $answer : null);
            if ($found !== []) {
                $errors[(string) $fieldId] = $found;
                continue;
            }
            $patch[$fieldId] = $answer;
        }
        if ($foreign !== []) {
            throw new InvalidNprInput('Only TSD assessment fields can be saved here; not allowed: ' . implode(', ', $foreign) . '.');
        }
        if ($errors !== []) {
            throw new NprValidationFailed('Some assessment answers are not valid.', $errors);
        }

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                'assessment_saved',
                'tsd',
                self::actor($user),
                [RequestStatus::WithTsd, RequestStatus::AwaitingScreening],
                null,
                sprintf('TSD assessment updated by %s', $user->displayName),
                payload: ['fields' => array_keys($patch)],
                answerPatch: $patch,
            ),
            NprWorkflowEvent::ASSESSMENT_SAVED,
        );
    }

    /**
     * Sends the assessed request to a nominated signatory.
     *
     * @throws NprWorkflowRefused SIGNING_POLICY_NOT_CONFIGURED
     * @throws NprValidationFailed Section 10 incomplete
     * @throws InvalidNprInput    signatory not nominated / not eligible
     */
    public function requestScreening(AuthenticatedUser $user, string $publicId, string $version, mixed $signatoryUserId, mixed $signatoryEmail): ?NprRequestView
    {
        $current = $this->asTsdWorker($user, $publicId);
        if ($current === null) {
            return null;
        }
        self::assertVersion($current, $version);
        self::assertStatus($current, RequestStatus::WithTsd);
        $this->assertSigningConfigured();

        $signatory = $this->people->findActiveWith(
            is_int($signatoryUserId) ? $signatoryUserId : null,
            is_string($signatoryEmail) ? $signatoryEmail : null,
            Module::PERMISSION_SCREENING_DECIDE,
        );
        if ($signatory === null || !$this->signing->nominates($signatory->emailAddress()->toString())) {
            throw new InvalidNprInput('A nominated screening signatory is required (signatoryUserId or signatoryEmail).');
        }
        $check = $this->validator->validateForScreening($current->answers);
        if (!$check['valid']) {
            throw new NprValidationFailed('Section 10 technical assessment is incomplete', $check['errors']);
        }
        $person = NprPeople::person($signatory);

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                'screening_requested',
                'tsd',
                self::actor($user),
                [RequestStatus::WithTsd],
                RequestStatus::AwaitingScreening,
                sprintf('TSD completed assessment and submitted for screening to %s (%s)', $person->name, $person->email),
                assignSignatory: $person,
            ),
            NprWorkflowEvent::SCREENING_REQUESTED,
        );
    }

    // ----- Signatory -----

    /**
     * The assigned signatory records the outcome.
     *
     * @throws NprWorkflowRefused SIGNING_POLICY_NOT_CONFIGURED, NOT_ALLOWED_IN_STATUS
     * @throws InvalidNprInput    outcome or rationale
     */
    public function decide(AuthenticatedUser $user, string $publicId, string $version, mixed $outcome, mixed $rationale, mixed $conditions): ?NprRequestView
    {
        $current = $this->requests->find($publicId);
        if ($current === null || !$this->policy->isAssignedSignatory($user, $current)) {
            return null;
        }
        self::assertVersion($current, $version);
        self::assertStatus($current, RequestStatus::AwaitingScreening);
        $this->assertSigningConfigured();
        if (!is_string($outcome) || !in_array($outcome, ['approved', 'deferred', 'rejected'], true)) {
            throw new InvalidNprInput('Invalid outcome. Must be approved, deferred, or rejected.');
        }
        $why = self::requiredText($rationale, 'A decision rationale is required');
        $terms = self::optionalText(is_string($conditions) ? $conditions : null);

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                'screening_decided',
                'screening_signatory',
                self::actor($user),
                [RequestStatus::AwaitingScreening],
                RequestStatus::ScreeningCompleted,
                sprintf('Screening outcome recorded: %s by %s. Rationale: %s', strtoupper($outcome), $user->displayName, $why),
                payload: ['outcome' => $outcome],
                assignSignatory: self::actor($user),
                decision: new NprScreeningDecision($outcome, $why, $terms ?? ''),
                answerPatch: [
                    'screening_outcome' => ['state' => 'provided', 'value' => $outcome],
                    'screening_rationale' => ['state' => 'provided', 'value' => $why],
                    'screening_conditions' => $terms !== null
                        ? ['state' => 'provided', 'value' => $terms]
                        : ['state' => 'not_applicable', 'reason' => 'No conditions'],
                    'decided_by' => ['state' => 'provided', 'value' => $user->displayName],
                    'decided_at' => ['state' => 'provided', 'value' => $this->stamp()],
                    'decided_revision' => ['state' => 'provided', 'value' => $current->revision],
                ],
            ),
            NprWorkflowEvent::SCREENING_DECIDED,
        );
    }

    // ----- internals -----

    private function managerExit(AuthenticatedUser $user, string $publicId, string $version, mixed $reason, string $endorsementAction, RequestStatus $to, string $action, string $event, string $missing, string $summaryFormat): ?NprRequestView
    {
        $current = $this->asManager($user, $publicId);
        if ($current === null) {
            return null;
        }
        self::assertVersion($current, $version);
        self::assertStatus($current, RequestStatus::AwaitingManager);
        $text = self::requiredText($reason, $missing);

        return $this->commit(
            $user,
            $current,
            $version,
            new NprTransition(
                $action,
                'manager',
                self::actor($user),
                [RequestStatus::AwaitingManager],
                $to,
                sprintf($summaryFormat, $text),
                payload: ['stage' => 'manager', 'reason' => $text],
                endorsement: ['action' => $endorsementAction, 'declaration' => false, 'comment' => $text],
            ),
            $event,
        );
    }

    private function commit(AuthenticatedUser $user, NprRequest $current, string $version, NprTransition $transition, string $eventName, bool $firstTsdHandoff = false): ?NprRequestView
    {
        $saved = $this->requests->transition($current->publicId, $version, $transition);
        if ($saved === null) {
            return null;
        }
        $this->events->record(new NprWorkflowEvent(
            $eventName,
            $saved->publicId,
            $saved->requestNumber,
            $saved->revision,
            $transition->actor,
            $current->status,
            $saved->status,
            $transition->summary,
            $firstTsdHandoff,
            $saved->updatedAt,
        ));

        return $this->view($user, $saved);
    }

    private function asManager(AuthenticatedUser $user, string $publicId): ?NprRequest
    {
        $request = $this->requests->find($publicId);

        return $request !== null && $this->policy->canReadAsManager($user, $request) && $request->status !== RequestStatus::Draft ? $request : null;
    }

    private function asTsdWorker(AuthenticatedUser $user, string $publicId): ?NprRequest
    {
        $request = $this->requests->find($publicId);

        return $request !== null
            && $this->policy->canReadAsTsd($user, $request)
            && NprAccessPolicy::holds($user, Module::PERMISSION_TSD_WORK) ? $request : null;
    }

    private function view(AuthenticatedUser $user, NprRequest $request): NprRequestView
    {
        return new NprRequestView($request, $this->policy->allowedActions($user, $request));
    }

    private function stamp(): string
    {
        return $this->clock->now()->format(self::STAMP);
    }

    private function assertSigningConfigured(): void
    {
        if (!$this->signing->isConfigured()) {
            throw new NprWorkflowRefused(
                NprWorkflowRefused::SIGNING_POLICY_NOT_CONFIGURED,
                'Screening is blocked: TSD has not nominated screening signatories yet. Set NPR_SCREENING_SIGNATORY_EMAILS once the signing policy is confirmed.',
            );
        }
    }

    private static function actor(AuthenticatedUser $user): NprPerson
    {
        return new NprPerson($user->userId, strtolower($user->emailAddress), $user->displayName);
    }

    private static function assertVersion(NprRequest $current, string $version): void
    {
        if (strcasecmp($current->version, trim($version)) !== 0) {
            throw new NprRequestConflict($current->version);
        }
    }

    private static function assertStatus(NprRequest $current, RequestStatus ...$allowed): void
    {
        if (!in_array($current->status, $allowed, true)) {
            throw NprWorkflowRefused::notAllowedIn($current->status->value);
        }
    }

    /** @throws InvalidNprInput */
    private static function requiredText(mixed $value, string $missing): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            throw new InvalidNprInput($missing);
        }

        return $text;
    }

    private static function optionalText(?string $value): ?string
    {
        $text = $value === null ? '' : trim($value);

        return $text === '' ? null : $text;
    }
}
