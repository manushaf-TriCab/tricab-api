<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Npr\Support\NprWorkflowWorld as W;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\Exception\NprValidationFailed;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Module;

/**
 * The request workflow end to end over the in-memory repository. Ports
 * npr-portal tests/test_backend.php (workflow sections) and
 * test_e2e_acceptance.php, to docs/ACCESS_AND_ROUTING.md.
 */
final class NprWorkflowTest extends TestCase
{
    public function test_the_whole_happy_path(): void
    {
        $w = new W();
        [$id, $version] = $w->completeDraft();

        $submitted = $w->workflow->submit($w->user(W::SALES), $id, $version);
        self::assertNotNull($submitted);
        self::assertSame(RequestStatus::AwaitingManager, $submitted->request->status);
        self::assertSame(W::SHANE, $submitted->request->manager?->userId);
        self::assertSame('shanep@tricab.com', $submitted->request->manager->email);
        self::assertNotNull($submitted->request->submittedAt);
        self::assertNull($submitted->request->tsdReceivedAt, 'TSD has not received it yet');
        self::assertSame(['read'], $submitted->allowedActions, 'Sales can no longer edit or submit');

        $endorsed = $w->workflow->endorse($w->user(W::SHANE), $id, $submitted->request->version, true, 'Priority customer');
        self::assertNotNull($endorsed);
        self::assertSame(RequestStatus::WithTsd, $endorsed->request->status);
        self::assertNotNull($endorsed->request->endorsedAt);
        self::assertNotNull($endorsed->request->tsdReceivedAt);
        self::assertSame(['read'], $endorsed->allowedActions, 'the manager has nothing more to do');

        $tsd = $w->user(W::TSD);
        $ack = $w->workflow->acknowledge($tsd, $id, $endorsed->request->version, true, 'Complete');
        self::assertNotNull($ack);
        self::assertNotNull($ack->request->tsdAcknowledgedAt);
        self::assertSame('yes', W::value($ack->request->answers, 'form_complete'));

        $owned = $w->workflow->assignOwner($tsd, $id, $ack->request->version, W::TSD_TWO, null);
        self::assertNotNull($owned);
        self::assertSame(W::TSD_TWO, $owned->request->tsdOwner?->userId);

        $assessed = $w->workflow->saveAssessment($tsd, $id, $owned->request->version, W::assessment());
        self::assertNotNull($assessed);

        $screening = $w->workflow->requestScreening($tsd, $id, $assessed->request->version, null, 'signatory@tricab.com');
        self::assertNotNull($screening);
        self::assertSame(RequestStatus::AwaitingScreening, $screening->request->status);
        self::assertSame(W::SIGNATORY, $screening->request->signatory?->userId);

        $decided = $w->workflow->decide($w->user(W::SIGNATORY), $id, $screening->request->version, 'approved', 'Strategic project.', 'Sample required.');
        self::assertNotNull($decided);
        self::assertSame(RequestStatus::ScreeningCompleted, $decided->request->status);
        self::assertSame('approved', $decided->request->screeningOutcome);
        self::assertSame('Strategic project.', $decided->request->screeningRationale);
        self::assertSame('Sample required.', $decided->request->screeningConditions);
        self::assertNotNull($decided->request->screeningDecidedAt);
        self::assertSame('approved', W::value($decided->request->answers, 'screening_outcome'));

        self::assertSame(
            ['sales_submitted', 'manager_endorsed', 'tsd_acknowledged', 'tsd_assigned', 'assessment_saved', 'screening_requested', 'screening_decided'],
            $w->events->names(),
            'one event per committed transition, named as in notifications.json',
        );
        self::assertSame(
            ['submitted_for_endorsement', 'manager_endorsed', 'tsd_acknowledged', 'tsd_assigned', 'assessment_saved', 'screening_requested', 'screening_decided'],
            array_map(static fn ($row): string => $row->action, $w->requests->activityLog($id)),
            'exactly one Activity row per transition',
        );
    }

    // ------------------------------------------------------------ submit

    public function test_submitting_without_a_manager_route_is_blocked_and_the_draft_stays_saved(): void
    {
        $w = new W(brisbaneRouted: false);
        [$id, $version] = $w->completeDraft();

        try {
            $w->workflow->submit($w->user(W::SALES), $id, $version);
            self::fail('Expected NprWorkflowRefused.');
        } catch (NprWorkflowRefused $refused) {
            self::assertSame('NPR_NO_MANAGER_ROUTE', $refused->reason);
            self::assertStringContainsString('Brisbane', $refused->getMessage());
        }

        $kept = $w->requests->find($id);
        self::assertNotNull($kept);
        self::assertSame(RequestStatus::Draft, $kept->status, 'never sent on to TSD');
        self::assertSame($version, $kept->version, 'nothing was written');
        self::assertSame([], $w->events->events);
    }

    public function test_an_inactive_manager_is_no_route(): void
    {
        $w = new W(brisbaneRouted: false);
        $w->directory->add(W::SHANE, 'shanep@tricab.com', 'Shane Plumridge', [], active: false);
        $w->route('Brisbane', W::SHANE);
        [$id, $version] = $w->completeDraft();

        $this->expectException(NprWorkflowRefused::class);
        $w->workflow->submit($w->user(W::SALES), $id, $version);
    }

    public function test_an_incomplete_draft_is_refused_with_field_errors(): void
    {
        $w = new W();
        $draft = $w->drafts->createDraft($w->user(W::SALES), [], null)->request;

        try {
            $w->workflow->submit($w->user(W::SALES), $draft->publicId, $draft->version);
            self::fail('Expected NprValidationFailed.');
        } catch (NprValidationFailed $failed) {
            self::assertNotSame([], $failed->errors);
        }
        self::assertSame(RequestStatus::Draft, $w->requests->find($draft->publicId)?->status);
    }

    public function test_the_branch_manager_cannot_submit_to_themselves(): void
    {
        $w = new W(brisbaneRouted: false);
        $w->route('Brisbane', W::SALES);
        [$id, $version] = $w->completeDraft();

        try {
            $w->workflow->submit($w->user(W::SALES), $id, $version);
            self::fail('Expected NprWorkflowRefused.');
        } catch (NprWorkflowRefused $refused) {
            self::assertSame('NPR_SELF_ENDORSEMENT_NOT_ALLOWED', $refused->reason);
        }
    }

    public function test_only_the_owner_submits_and_not_twice(): void
    {
        $w = new W();
        [$id, $version] = $w->completeDraft();

        self::assertNull($w->workflow->submit($w->user(W::OTHER_MANAGER), $id, $version), 'someone else: not found');
        $first = $w->workflow->submit($w->user(W::SALES), $id, $version);
        self::assertNotNull($first);

        $this->expectException(NprRequestNotEditable::class);
        $w->workflow->submit($w->user(W::SALES), $id, $first->request->version);
    }

    // ------------------------------------------------------------ manager

    public function test_the_endorsement_records_the_exact_revision_and_the_declaration(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();

        $w->workflow->endorse($w->user(W::SHANE), $id, $version, true, 'Fine');

        $log = $w->requests->endorsementLog($id);
        self::assertCount(1, $log);
        self::assertSame('endorsed', $log[0]->action);
        self::assertSame(1, $log[0]->revision);
        self::assertTrue($log[0]->declaration);
        self::assertSame('Fine', $log[0]->comment);
        self::assertSame(W::SHANE, $log[0]->manager->userId);
    }

    public function test_endorsing_needs_the_declaration(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();

        foreach ([false, null, 'yes'] as $declaration) {
            try {
                $w->workflow->endorse($w->user(W::SHANE), $id, $version, $declaration, null);
                self::fail('Expected InvalidNprInput.');
            } catch (InvalidNprInput) {
                self::assertSame(RequestStatus::AwaitingManager, $w->requests->find($id)?->status);
            }
        }
        self::assertSame([], $w->requests->endorsementLog($id));
    }

    public function test_self_endorsement_is_refused(): void
    {
        // Amanda is both the requester and the assigned manager (as if routed to her after she submitted).
        $w = new W();
        [$id] = $w->submitted();
        $w->directory->add(W::SALES, 'amandaw@tricab.com', 'Amanda Williams', [
            ...Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER],
            ...Module::ROLE_PERMISSIONS[Module::ROLE_MANAGER],
        ]);
        $request = $w->requests->find($id);
        self::assertNotNull($request);
        $w->requests->withRequest($request->with(['manager' => new NprPerson(W::SALES, 'amandaw@tricab.com', 'Amanda Williams')]));
        $amanda = $w->user(W::SALES);

        $view = $w->workflow->getForManager($amanda, $id);
        self::assertNotNull($view);
        self::assertNotContains('endorse', $view->allowedActions, 'the button is not offered');

        try {
            $w->workflow->endorse($amanda, $id, $request->version, true, null);
            self::fail('Expected NprWorkflowRefused.');
        } catch (NprWorkflowRefused $refused) {
            self::assertSame('NPR_SELF_ENDORSEMENT_NOT_ALLOWED', $refused->reason);
        }
        self::assertSame(RequestStatus::AwaitingManager, $w->requests->find($id)?->status);
    }

    public function test_a_manager_return_goes_back_to_sales_and_is_hidden_from_tsd(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();

        $returned = $w->workflow->managerReturn($w->user(W::SHANE), $id, $version, 'Please confirm the volume');
        self::assertNotNull($returned);
        self::assertSame(RequestStatus::ReturnedToSales, $returned->request->status);

        $request = $w->requests->find($id);
        self::assertNotNull($request);
        self::assertFalse($request->isVisibleToTsd(), 'a manager-stage return never reached TSD');
        self::assertSame([], $w->requests->listForTsd());
        self::assertNull($w->workflow->getForManager($w->user(W::OTHER_MANAGER), $id));
        self::assertNull($w->drafts->getForTsd($w->user(W::TSD), $id));

        $last = $w->requests->lastActivity($id);
        self::assertSame('manager_returned', $last->action);
        self::assertSame(RequestStatus::AwaitingManager, $last->before, 'the originating stage is recorded');
        self::assertSame(['stage' => 'manager', 'reason' => 'Please confirm the volume'], $last->payload);
        self::assertSame('returned', $w->requests->endorsementLog($id)[0]->action);
        self::assertFalse($w->requests->endorsementLog($id)[0]->declaration);
        self::assertSame(['sales_submitted', 'manager_returned'], $w->events->names());
    }

    public function test_a_return_needs_a_reason(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();

        $this->expectException(InvalidNprInput::class);
        $w->workflow->managerReturn($w->user(W::SHANE), $id, $version, '  ');
    }

    public function test_a_manager_rejection_cancels(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();

        $rejected = $w->workflow->managerReject($w->user(W::SHANE), $id, $version, 'Not a real opportunity');

        self::assertNotNull($rejected);
        self::assertSame(RequestStatus::Cancelled, $rejected->request->status);
        self::assertSame('rejected', $w->requests->endorsementLog($id)[0]->action);
        self::assertSame(['sales_submitted', 'manager_rejected'], $w->events->names());
    }

    public function test_a_returned_request_is_amended_and_resubmitted_to_the_manager(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();
        $returned = $w->workflow->managerReturn($w->user(W::SHANE), $id, $version, 'Add detail');
        self::assertNotNull($returned);

        $sales = $w->user(W::SALES);
        $amended = $w->drafts->saveDraft($sales, $id, $returned->request->version, NprFixtures::validRequestAnswers(), NprFixtures::validVariants(), 'Added detail');
        self::assertNotNull($amended);
        self::assertSame(2, $amended->request->revision, 'a new immutable revision');

        $again = $w->workflow->submit($sales, $id, $amended->request->version);
        self::assertNotNull($again);
        self::assertSame(RequestStatus::AwaitingManager, $again->request->status);
        self::assertSame(['sales_submitted', 'manager_returned', 'sales_resubmitted'], $w->events->names());

        $endorsed = $w->workflow->endorse($w->user(W::SHANE), $id, $again->request->version, true, null);
        self::assertNotNull($endorsed);
        self::assertSame(2, $w->requests->lastEndorsement($id)->revision, 'the endorsement names the revision it signed');
    }

    public function test_a_repeat_endorsement_keeps_the_original_tsd_receipt_and_only_the_first_is_the_handoff(): void
    {
        $w = new W();
        [$id, $version] = $w->withTsd();
        $first = $w->requests->find($id)?->tsdReceivedAt;
        self::assertNotNull($first);

        // TSD returns it; Sales amends and resubmits; the manager endorses again, a day later.
        $returned = $w->workflow->tsdReturn($w->user(W::TSD), $id, $version, 'Need drawings');
        self::assertNotNull($returned);
        $amended = $w->drafts->saveDraft($w->user(W::SALES), $id, $returned->request->version, NprFixtures::validRequestAnswers(), NprFixtures::validVariants(), null);
        self::assertNotNull($amended);
        $resubmitted = $w->workflow->submit($w->user(W::SALES), $id, $amended->request->version);
        self::assertNotNull($resubmitted);
        $w->clock->advance('+1 day');
        $again = $w->workflow->endorse($w->user(W::SHANE), $id, $resubmitted->request->version, true, null);

        self::assertNotNull($again);
        self::assertEquals($first, $again->request->tsdReceivedAt, 'the original receipt is kept');
        self::assertGreaterThan($first, $again->request->endorsedAt);
        $handoffs = array_values(array_filter($w->events->events, static fn (NprWorkflowEvent $e): bool => $e->name === 'manager_endorsed'));
        self::assertCount(2, $handoffs);
        self::assertTrue($handoffs[0]->firstTsdHandoff);
        self::assertFalse($handoffs[1]->firstTsdHandoff);
    }

    // ------------------------------------------------------------ TSD

    public function test_a_tsd_return_stays_visible_to_tsd(): void
    {
        $w = new W();
        [$id, $version] = $w->withTsd();

        $returned = $w->workflow->tsdReturn($w->user(W::TSD), $id, $version, 'Please attach the customer specification');
        self::assertNotNull($returned);
        self::assertSame(RequestStatus::ReturnedToSales, $returned->request->status);

        $request = $w->requests->find($id);
        self::assertNotNull($request);
        self::assertTrue($request->isVisibleToTsd(), 'TSD still sees what it returned');
        self::assertCount(1, $w->requests->listForTsd());
        $last = $w->requests->lastActivity($id);
        self::assertSame(['stage' => 'tsd', 'reason' => 'Please attach the customer specification'], $last->payload);
        self::assertSame(RequestStatus::WithTsd, $last->before);
        self::assertSame('Please attach the customer specification', W::value($returned->request->answers, 'receipt_notes'));
    }

    public function test_an_assessment_saves_tsd_fields_and_never_touches_sales_answers(): void
    {
        $w = new W();
        [$id, $version] = $w->withTsd();

        $saved = $w->workflow->saveAssessment($w->user(W::TSD), $id, $version, ['technical_risks' => ['state' => 'provided', 'value' => 'Tooling']]);
        self::assertNotNull($saved);
        self::assertSame('Tooling', W::value($saved->request->answers, 'technical_risks'));
        self::assertSame('BHP', W::value($saved->request->answers, 'customer_name'));

        $attempts = [
            'customer_name' => ['state' => 'provided', 'value' => 'Hacked'],
            'unknown_field' => ['state' => 'provided', 'value' => 1],
            'screening_outcome' => ['state' => 'provided', 'value' => 'approved'],
        ];
        foreach ($attempts as $field => $answer) {
            try {
                $w->workflow->saveAssessment($w->user(W::TSD), $id, $saved->request->version, [$field => $answer]);
                self::fail('Expected InvalidNprInput.');
            } catch (InvalidNprInput $e) {
                self::assertStringContainsString($field, $e->getMessage());
            }
        }
        $after = $w->requests->find($id);
        self::assertSame('BHP', W::value($after->answers ?? [], 'customer_name'));
        self::assertArrayNotHasKey('screening_outcome', $after->answers ?? []);
    }

    public function test_an_invalid_assessment_answer_is_refused(): void
    {
        $w = new W();
        [$id, $version] = $w->withTsd();

        $this->expectException(NprValidationFailed::class);
        $w->workflow->saveAssessment($w->user(W::TSD), $id, $version, ['technical_feasibility' => ['state' => 'provided', 'value' => 'perhaps']]);
    }

    public function test_screening_needs_a_complete_section_10_and_a_nominated_signatory(): void
    {
        $w = new W();
        [$id, $version] = $w->withTsd();
        $tsd = $w->user(W::TSD);

        try {
            $w->workflow->requestScreening($tsd, $id, $version, null, 'signatory@tricab.com');
            self::fail('Expected NprValidationFailed.');
        } catch (NprValidationFailed $failed) {
            self::assertArrayHasKey('existing_product_fit', $failed->errors);
        }

        $assessed = $w->workflow->saveAssessment($tsd, $id, $version, W::assessment());
        self::assertNotNull($assessed);
        foreach ([[null, 'tsdtwo@tricab.com'], [null, 'nobody@tricab.com'], [null, null]] as [$userId, $email]) {
            try {
                $w->workflow->requestScreening($tsd, $id, $assessed->request->version, $userId, $email);
                self::fail('Expected InvalidNprInput.');
            } catch (InvalidNprInput) {
                self::assertSame(RequestStatus::WithTsd, $w->requests->find($id)?->status);
            }
        }
    }

    public function test_screening_stays_blocked_until_signatories_are_nominated(): void
    {
        $w = new W(nominated: []);
        [$id, $version] = $w->withTsd();
        $tsd = $w->user(W::TSD);
        $assessed = $w->workflow->saveAssessment($tsd, $id, $version, W::assessment());
        self::assertNotNull($assessed);

        try {
            $w->workflow->requestScreening($tsd, $id, $assessed->request->version, W::SIGNATORY, null);
            self::fail('Expected NprWorkflowRefused.');
        } catch (NprWorkflowRefused $refused) {
            self::assertSame('NPR_SIGNING_POLICY_NOT_CONFIGURED', $refused->reason);
            self::assertStringContainsString('NPR_SCREENING_SIGNATORY_EMAILS', $refused->getMessage());
        }
        self::assertSame(RequestStatus::WithTsd, $w->requests->find($id)?->status);
    }

    public function test_deciding_is_blocked_while_the_signing_policy_is_unconfigured(): void
    {
        $w = new W(nominated: []);
        [$id] = $w->withTsd();
        $request = $w->requests->find($id);
        self::assertNotNull($request);
        $signatory = new NprPerson(W::SIGNATORY, 'signatory@tricab.com', 'Sign Atory');
        $waiting = $request->with(['status' => RequestStatus::AwaitingScreening, 'signatory' => $signatory]);
        $w->requests->withRequest($waiting);

        try {
            $w->workflow->decide($w->user(W::SIGNATORY), $id, $waiting->version, 'approved', 'ok', null);
            self::fail('Expected NprWorkflowRefused.');
        } catch (NprWorkflowRefused $refused) {
            self::assertSame('NPR_SIGNING_POLICY_NOT_CONFIGURED', $refused->reason);
        }
    }

    public function test_only_the_assigned_signatory_decides_and_the_outcome_is_checked(): void
    {
        $w = new W();
        [$id, $version] = $w->withTsd();
        $tsd = $w->user(W::TSD);
        $assessed = $w->workflow->saveAssessment($tsd, $id, $version, W::assessment());
        self::assertNotNull($assessed);
        $screening = $w->workflow->requestScreening($tsd, $id, $assessed->request->version, W::SIGNATORY, null);
        self::assertNotNull($screening);
        $v = $screening->request->version;

        self::assertNull($w->workflow->decide($tsd, $id, $v, 'approved', 'I approve', null), 'TSD is not the signatory');
        self::assertNull($w->workflow->decide($w->user(W::SALES), $id, $v, 'approved', 'I approve', null));
        foreach ([['maybe', 'why'], ['approved', ' ']] as [$outcome, $why]) {
            try {
                $w->workflow->decide($w->user(W::SIGNATORY), $id, $v, $outcome, $why, null);
                self::fail('Expected InvalidNprInput.');
            } catch (InvalidNprInput) {
                self::assertSame(RequestStatus::AwaitingScreening, $w->requests->find($id)?->status);
            }
        }
        $deferred = $w->workflow->decide($w->user(W::SIGNATORY), $id, $v, 'deferred', 'Revisit next quarter', null);
        self::assertNotNull($deferred);
        self::assertSame('deferred', $deferred->request->screeningOutcome);
        self::assertSame('', $deferred->request->screeningConditions);
        self::assertSame(['read'], $deferred->allowedActions);
    }

    // ------------------------------------------------------------ guards

    public function test_a_stale_version_is_a_conflict_and_writes_nothing(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();
        $w->workflow->endorse($w->user(W::SHANE), $id, $version, true, null);
        $activity = count($w->requests->activityLog($id));

        $stale = [
            fn () => $w->workflow->managerReturn($w->user(W::SHANE), $id, $version, 'late'),
            fn () => $w->workflow->acknowledge($w->user(W::TSD), $id, $version, true, null),
        ];
        foreach ($stale as $attempt) {
            try {
                $attempt();
                self::fail('Expected NprRequestConflict.');
            } catch (NprRequestConflict $conflict) {
                self::assertSame($w->requests->find($id)?->version, $conflict->currentVersion);
            }
        }
        self::assertCount($activity, $w->requests->activityLog($id));
    }

    public function test_the_wrong_person_gets_not_found(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();

        self::assertNull($w->workflow->endorse($w->user(W::OTHER_MANAGER), $id, $version, true, null), 'a manager of another branch');
        self::assertNull($w->workflow->managerReturn($w->user(W::OTHER_MANAGER), $id, $version, 'no'));
        self::assertNull($w->workflow->managerReject($w->user(W::OTHER_MANAGER), $id, $version, 'no'));
        self::assertNull($w->workflow->endorse($w->user(W::SALES), $id, $version, true, null), 'the requester');
        self::assertNull($w->workflow->endorse($w->user(W::TSD), $id, $version, true, null), 'TSD, which has no endorse permission');
        self::assertNull($w->workflow->getForManager($w->user(W::OTHER_MANAGER), $id));
        self::assertSame([], $w->workflow->listForManager($w->user(W::OTHER_MANAGER)));
        self::assertCount(1, $w->workflow->listForManager($w->user(W::SHANE)));
        self::assertNull($w->workflow->acknowledge($w->user(W::TSD), $id, $version, true, null), 'TSD cannot touch what has not reached it');
        self::assertNull($w->workflow->acknowledge($w->user(W::TSD), 'nope', $version, true, null));
        self::assertSame(RequestStatus::AwaitingManager, $w->requests->find($id)?->status);
        self::assertSame(['sales_submitted'], $w->events->names(), 'none of that committed anything');
    }

    public function test_a_step_out_of_order_is_refused(): void
    {
        $w = new W();
        [$id, $version] = $w->withTsd();

        $steps = [
            fn () => $w->workflow->endorse($w->user(W::SHANE), $id, $version, true, null),
            fn () => $w->workflow->managerReturn($w->user(W::SHANE), $id, $version, 'late'),
        ];
        foreach ($steps as $step) {
            try {
                $step();
                self::fail('Expected a refusal.');
            } catch (NprWorkflowRefused $refused) {
                self::assertSame('NPR_ACTION_NOT_ALLOWED', $refused->reason);
            }
        }
    }

    public function test_allowed_actions_follow_the_request(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();

        $manager = $w->workflow->getForManager($w->user(W::SHANE), $id);
        self::assertNotNull($manager);
        self::assertSame(['read', 'endorse', 'return', 'reject'], $manager->allowedActions);

        $w->workflow->endorse($w->user(W::SHANE), $id, $version, true, null);
        $tsd = $w->drafts->getForTsd($w->user(W::TSD), $id);
        self::assertNotNull($tsd);
        self::assertSame(['read', 'acknowledge', 'assign', 'assess', 'tsd_return', 'request_screening'], $tsd->allowedActions);
    }
}
