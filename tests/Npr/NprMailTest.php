<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Npr\Support\NprWorkflowWorld as W;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;
use TriCab\Core\Npr\Infrastructure\InMemoryNprMailOutbox;
use TriCab\Core\Npr\Mail\NewNprOutboxEntry;
use TriCab\Core\Npr\Mail\NprMailMode;
use TriCab\Core\Npr\Mail\NprMailOutbox;
use TriCab\Core\Npr\Mail\NprNotificationPlanner;
use TriCab\Core\Npr\Mail\NprOutboxEntry;
use TriCab\Core\Npr\Mail\OutboxNprEventSink;

/** Workflow email (plan N2.4): recipients, modes, failure handling, the retry sweep and atomicity. */
final class NprMailTest extends TestCase
{
    private const SALES = 'amandaw@tricab.com';
    private const MANAGER = 'shanep@tricab.com';

    /** @return list<NprOutboxEntry> */
    private static function rows(W $w, string $event): array
    {
        $rows = [];
        foreach ($w->outbox->all() as $row) {
            if ($row->event === $event) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function only(W $w, string $event): NprOutboxEntry
    {
        $rows = self::rows($w, $event);
        self::assertCount(1, $rows, $event . ' should have exactly one outbox row.');

        return $rows[0];
    }

    public function test_the_whole_flow_writes_the_recipient_matrix_and_sends_it(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();
        $v = W::version($w->workflow->endorse($w->user(W::SHANE), $id, $version, true, null));
        $v = W::version($w->workflow->acknowledge($w->user(W::TSD), $id, $v, true, null));
        $v = W::version($w->workflow->assignOwner($w->user(W::TSD), $id, $v, W::TSD_TWO, null));
        $v = W::version($w->workflow->saveAssessment($w->user(W::TSD), $id, $v, W::assessment()));
        $v = W::version($w->workflow->requestScreening($w->user(W::TSD_TWO), $id, $v, W::SIGNATORY, null));
        $w->workflow->decide($w->user(W::SIGNATORY), $id, $v, 'approved', 'Fine.', null);

        $matrix = [
            'sales_submitted' => [[self::MANAGER], [self::SALES]],
            'manager_endorsed' => [[W::TSD_QUEUE], [self::SALES, self::MANAGER, W::DEPUTY]],
            'tsd_acknowledged' => [[self::SALES, self::MANAGER], []],
            'tsd_assigned' => [[self::SALES, self::MANAGER, 'tsdtwo@tricab.com'], []],
            'assessment_saved' => [[self::SALES, self::MANAGER], []],
            'screening_requested' => [['signatory@tricab.com'], [self::SALES, self::MANAGER, 'tsdtwo@tricab.com']],
            'screening_decided' => [[self::SALES, self::MANAGER], ['tsdtwo@tricab.com', W::DEPUTY]],
        ];
        foreach ($matrix as $event => [$to, $cc]) {
            $row = self::only($w, $event);
            self::assertSame($to, $row->to, $event . ' to');
            self::assertSame($cc, $row->cc, $event . ' cc');
            self::assertSame('sent', $row->status, $event);
            self::assertFalse($row->hasMissingRecipients());
        }
        self::assertCount(count($matrix), $w->transport->sent, 'one message per committed step');
    }

    public function test_robby_is_copied_on_the_first_handoff_and_the_final_outcome_only(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();
        $v = W::version($w->workflow->endorse($w->user(W::SHANE), $id, $version, true, null));
        // TSD returns it, Sales resubmits, the manager endorses again: not a new initial handoff.
        $v = W::version($w->workflow->tsdReturn($w->user(W::TSD), $id, $v, 'Need drawings'));
        $v = W::version($w->workflow->submit($w->user(W::SALES), $id, $v));
        $w->workflow->endorse($w->user(W::SHANE), $id, $v, true, null);

        $endorsed = self::rows($w, 'manager_endorsed');
        self::assertCount(2, $endorsed);
        self::assertContains(W::DEPUTY, $endorsed[0]->cc);
        self::assertNotContains(W::DEPUTY, $endorsed[1]->cc, 'a repeat endorsement does not copy Robby again');
        self::assertSame([W::TSD_QUEUE], $endorsed[1]->to, 'its TSD, Sales and manager notice stays');
        self::assertSame([self::SALES, self::MANAGER], $endorsed[1]->cc);

        foreach ($w->outbox->all() as $row) {
            if (!in_array($row->event, ['manager_endorsed', 'screening_decided'], true)) {
                self::assertNotContains(W::DEPUTY, [...$row->to, ...$row->cc], $row->event . ' must not copy Robby');
            }
        }
        $returned = self::only($w, 'tsd_returned');
        self::assertSame([self::SALES], $returned->to);
        self::assertSame([self::MANAGER], $returned->cc, 'no TSD owner is named yet, and that is not a failure');
        self::assertFalse($returned->hasMissingRecipients());
        $resubmitted = self::only($w, 'sales_resubmitted');
        self::assertSame([self::MANAGER], $resubmitted->to);
        self::assertSame([self::SALES], $resubmitted->cc);
    }

    public function test_manager_returns_and_rejections_go_to_sales_with_the_manager_copied(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();
        $w->workflow->managerReturn($w->user(W::SHANE), $id, $version, 'Add the customer drawing');
        [$id2, $version2] = $w->submitted();
        $w->workflow->managerReject($w->user(W::SHANE), $id2, $version2, 'Not viable');

        foreach (['manager_returned', 'manager_rejected'] as $event) {
            $row = self::only($w, $event);
            self::assertSame([self::SALES], $row->to);
            self::assertSame([self::MANAGER], $row->cc);
        }
    }

    public function test_draft_saves_and_reads_write_no_mail(): void
    {
        $w = new W();
        $w->completeDraft();

        self::assertSame([], $w->outbox->all());
        self::assertSame([], $w->transport->sent);
    }

    public function test_addresses_are_snapshotted_and_deduplicated(): void
    {
        $w = new W(tsdQueue: 'AMANDAW@tricab.com');
        $w->withTsd();

        $row = self::only($w, 'manager_endorsed');
        self::assertSame([self::SALES], $row->to, 'lower-cased');
        self::assertSame([self::MANAGER, W::DEPUTY], $row->cc, 'the To address is not repeated in Cc');
    }

    public function test_test_mode_sends_only_to_the_test_recipient(): void
    {
        $w = new W(mode: NprMailMode::Test, testRecipient: 'it.tester@example.test');
        $w->withTsd();

        self::assertCount(2, $w->transport->sent);
        foreach ($w->transport->sent as $message) {
            self::assertSame(['it.tester@example.test'], $message->to);
            self::assertSame([], $message->cc, 'no real recipient is copied');
            self::assertStringStartsWith('[TEST] ', $message->subject);
            self::assertStringNotContainsString('To: ' . W::DEPUTY, $message->subject);
        }
        $endorsed = $w->transport->sent[1];
        self::assertStringContainsString('Intended for: ' . W::TSD_QUEUE, $endorsed->text);
        self::assertStringContainsString(W::DEPUTY, $endorsed->text, 'the intended recipients are named in the body');
        // The ledger keeps the real snapshot.
        self::assertContains(W::DEPUTY, self::only($w, 'manager_endorsed')->cc);
    }

    public function test_test_mode_without_a_test_recipient_fails_visibly(): void
    {
        $w = new W(mode: NprMailMode::Test, testRecipient: '');
        $w->submitted();

        $row = self::only($w, 'sales_submitted');
        self::assertSame('failed', $row->status);
        self::assertStringContainsString('NPR_MAIL_TEST_RECIPIENT', (string) $row->error);
        self::assertSame([], $w->transport->sent);
    }

    public function test_a_missing_required_recipient_is_a_visible_failed_delivery_not_a_silent_drop(): void
    {
        $w = new W(tsdQueue: '', deputy: '');
        $w->withTsd();

        $row = self::only($w, 'manager_endorsed');
        self::assertTrue($row->hasMissingRecipients());
        $missing = array_values(array_filter($row->deliveries, static fn ($d): bool => $d->isMissingRecipient()));
        self::assertCount(2, $missing);
        self::assertSame(['(missing: tsd_queue)', '(missing: deputy_ceo)'], array_map(static fn ($d): string => $d->email, $missing));
        foreach ($missing as $delivery) {
            self::assertSame('failed', $delivery->status);
            self::assertNotEmpty($delivery->error);
        }
        // The addresses that did resolve are still written to, with no To (Cc only).
        self::assertSame([], $row->to);
        self::assertSame([self::SALES, self::MANAGER], $row->cc);
        self::assertSame('sent', $row->status);
        $sent = $w->transport->sent[1];
        self::assertSame([], $sent->to);
        self::assertSame([self::SALES, self::MANAGER], $sent->cc);
    }

    public function test_nothing_deliverable_is_a_failed_row_that_is_still_recorded(): void
    {
        $w = new W(tsdQueue: '');
        $planner = new NprNotificationPlanner('', '');
        $sink = new OutboxNprEventSink($planner, $w->outbox, NprMailMode::Live);
        $event = new NprWorkflowEvent(NprWorkflowEvent::SCREENING_REQUESTED, 'x', 'N', 1, new \TriCab\Core\Npr\Domain\NprPerson(1, 'a@b.test', 'A'), null, \TriCab\Core\Npr\Domain\RequestStatus::AwaitingScreening, 's', false, new \DateTimeImmutable());
        $request = NprFixtures::record('11111111-1111-4111-8111-111111111111', 1, \TriCab\Core\Npr\Domain\RequestStatus::AwaitingScreening);

        $sink->record($event, $request->with(['requesterEmail' => '']), 1, 1);

        $row = $w->outbox->all()[0];
        self::assertSame('failed', $row->status, 'no signatory, no requester, no manager');
        self::assertTrue($row->hasMissingRecipients());
    }

    public function test_mail_off_writes_suppressed_rows_and_sends_nothing(): void
    {
        $w = new W(mode: NprMailMode::Off);
        $w->submitted();

        $row = self::only($w, 'sales_submitted');
        self::assertSame('suppressed', $row->status);
        self::assertSame([self::MANAGER], $row->to, 'the snapshot is still kept');
        self::assertSame([], $w->transport->sent);
        self::assertSame('suppressed', $row->deliveries[0]->status);
        self::assertSame(0, $w->outbox->outstanding(), 'the sweep owes nothing for a suppressed row');

        // An administrator can reopen it, but with mail off nothing is sent.
        $reopened = $w->mail->retry($row->id);
        self::assertSame('pending', $reopened?->status);
        self::assertSame([], $w->transport->sent);
    }

    public function test_a_failed_send_never_undoes_the_business_change(): void
    {
        $w = new W();
        $w->transport->failing = true;

        [$id] = $w->submitted();

        self::assertSame('awaiting_manager', $w->requests->find($id)?->status->value);
        $row = self::only($w, 'sales_submitted');
        self::assertSame('failed', $row->status);
        self::assertSame(1, $row->attempts);
        self::assertSame('Microsoft Graph sendMail answered 503.', $row->error);
        self::assertSame('failed', $row->deliveries[0]->status);
        self::assertSame(1, $w->transport->wakes, 'the sweep is woken');
    }

    public function test_the_sweep_retries_after_the_backoff_and_then_stops_at_five_attempts(): void
    {
        $w = new W();
        $w->transport->failing = true;
        $w->submitted();

        self::assertSame(['sent' => 0, 'failed' => 0, 'outstanding' => 1], $w->mail->sweep(), 'not due yet: the backoff is a minute');

        $w->transport->failing = false;
        $w->clock->advance('+61 seconds');
        self::assertSame(['sent' => 1, 'failed' => 0, 'outstanding' => 0], $w->mail->sweep());
        self::assertSame('sent', self::only($w, 'sales_submitted')->status);
        self::assertCount(1, $w->transport->sent);

        // A row that keeps failing is given up after five attempts.
        $w2 = new W();
        $w2->transport->failing = true;
        $w2->submitted();
        foreach ([61, 301, 901, 3601] as $seconds) {
            $w2->clock->advance('+' . $seconds . ' seconds');
            $w2->mail->sweep();
        }
        self::assertSame(5, self::only($w2, 'sales_submitted')->attempts);
        self::assertSame(0, $w2->outbox->outstanding());
        $w2->clock->advance('+1 day');
        self::assertSame(['sent' => 0, 'failed' => 0, 'outstanding' => 0], $w2->mail->sweep(), 'given up');

        // The administrator's retry resets it.
        $w2->transport->failing = false;
        $retried = $w2->mail->retry(self::only($w2, 'sales_submitted')->id);
        self::assertSame('sent', $retried?->status);
    }

    public function test_a_retry_does_not_repeat_the_workflow_step(): void
    {
        $w = new W();
        $w->transport->failing = true;
        [$id] = $w->submitted();
        $before = $w->requests->find($id);
        $w->transport->failing = false;

        $w->mail->retry(self::only($w, 'sales_submitted')->id);

        self::assertSame($before?->version, $w->requests->find($id)?->version);
        self::assertCount(1, array_filter($w->requests->activityLog($id), static fn ($a): bool => $a->action === 'submitted_for_endorsement'));
        self::assertNull($w->mail->retry(9999), 'no such row');
    }

    public function test_the_outbox_row_rolls_back_with_the_transition(): void
    {
        $failing = new class () implements NprMailOutbox {
            public function enqueue(NewNprOutboxEntry $entry, int $activityId, int $requestId): int
            {
                throw new \RuntimeException('outbox write failed');
            }

            public function pendingForRequest(string $requestPublicId): array
            {
                return [];
            }

            public function retryable(int $limit): array
            {
                return [];
            }

            public function find(int $id): ?NprOutboxEntry
            {
                return null;
            }

            public function recent(int $limit, ?string $status): array
            {
                return [];
            }

            public function outstanding(): int
            {
                return 0;
            }

            public function recordAttempt(int $id, bool $sent, ?string $error, \DateTimeImmutable $now): void
            {
            }

            public function reopen(int $id): bool
            {
                return false;
            }
        };
        $w = new W();
        [$id, $version] = $w->completeDraft();
        $repository = new \TriCab\Core\Npr\Infrastructure\InMemoryNprRequestRepository(
            $w->clock,
            new OutboxNprEventSink(new NprNotificationPlanner('', ''), $failing, NprMailMode::Live),
        );
        $repository->withRequest($w->requests->find($id) ?? throw new \LogicException());

        try {
            $repository->transition($id, $version, new \TriCab\Core\Npr\Domain\NprTransition(
                'submitted_for_endorsement',
                'requester',
                new \TriCab\Core\Npr\Domain\NprPerson(W::SALES, self::SALES, 'Amanda'),
                [\TriCab\Core\Npr\Domain\RequestStatus::Draft],
                \TriCab\Core\Npr\Domain\RequestStatus::AwaitingManager,
                'Submitted',
                markSubmitted: true,
                event: NprWorkflowEvent::SALES_SUBMITTED,
            ));
            self::fail('The outbox failure must fail the transition.');
        } catch (\RuntimeException $e) {
            self::assertSame('outbox write failed', $e->getMessage());
        }

        $kept = $repository->find($id);
        self::assertSame('draft', $kept?->status->value, 'the business change is rolled back');
        self::assertSame($version, $kept->version);
        self::assertSame([], $repository->activityLog($id), 'and so is its Activity row');
    }

    public function test_a_transition_and_its_outbox_row_are_written_together(): void
    {
        $w = new W();
        [$id, $version] = $w->submitted();

        self::assertCount(1, $w->outbox->all());
        self::assertSame('awaiting_manager', $w->requests->find($id)?->status->value);
        $w->workflow->managerReturn($w->user(W::SHANE), $id, $version, 'More detail');
        self::assertCount(2, $w->outbox->all());
        self::assertInstanceOf(InMemoryNprMailOutbox::class, $w->outbox);
    }

    public function test_templates_are_short_and_carry_one_link_that_routes_by_role(): void
    {
        $w = new W();
        [$id] = $w->withTsd();

        $submitted = $w->transport->sent[0];
        self::assertStringContainsString('Open in TriCab: https://tricab.ai/npr/open/' . $id, $submitted->text);
        self::assertSame(1, substr_count($submitted->html, '<a href='), 'one link, whoever reads it');
        self::assertStringContainsString('>Open in TriCab</a>', $submitted->html);
        self::assertStringContainsString('Action required: endorsement needed', $submitted->subject);
        $endorsed = $w->transport->sent[1];
        self::assertStringContainsString('https://tricab.ai/npr/open/' . $id, $endorsed->text);
        self::assertStringNotContainsString('/admin/tsd/npr/', $endorsed->text);
        self::assertStringContainsString('NPR-2026-', $endorsed->subject);
        self::assertLessThan(1600, strlen($endorsed->text));
    }
}
