<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Npr\Application\Service\NprAccessPolicy;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Infrastructure\InMemoryNprRequestRepository;
use TriCab\Core\Npr\Module;

/** Drafts, ownership, revisions and `allowedActions` (portal test_backend.php section 6, AccessControl). */
final class NprRequestServiceTest extends TestCase
{
    private const OTHER = '00000000-0000-4000-8000-0000000000a1';

    private InMemoryNprRequestRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNprRequestRepository();
    }

    public function test_a_draft_is_opened_for_the_signed_in_person(): void
    {
        $view = NprFixtures::service($this->repository)->createDraft(NprFixtures::requester(), [
            'request_title' => ['state' => 'provided', 'value' => 'Mining Trailing Cable 33kV'],
            'customer_name' => ['state' => 'provided', 'value' => 'BHP'],
        ], null);

        $request = $view->request;
        self::assertMatchesRegularExpression('/^NPR-\d{4}-0001$/', $request->requestNumber);
        self::assertSame(RequestStatus::Draft, $request->status);
        self::assertSame(1, $request->revision);
        self::assertSame('Mining Trailing Cable 33kV', $request->title);
        self::assertSame('BHP', $request->customerName);
        self::assertSame('standard', $request->urgencyLevel);
        self::assertSame(10, $request->requesterUserId);
        self::assertSame('amandaw@tricab.com', $request->requesterEmail);
        self::assertSame('var_1', $request->variants[0]['id'], 'the source starts a draft with one default variant');
    }

    public function test_a_draft_with_no_title_is_untitled(): void
    {
        $view = NprFixtures::service($this->repository)->createDraft(NprFixtures::requester(), [], null);

        self::assertSame('Untitled New Product Request', $view->request->title);
    }

    public function test_branch_comes_from_the_roster_by_email_else_unassigned(): void
    {
        $service = NprFixtures::service($this->repository);

        $known = $service->createDraft(NprFixtures::requester(), [], null);
        $unknown = $service->createDraft(NprFixtures::user(11, Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER], 'nobody@example.com'), [], null);

        self::assertSame('Adelaide', $service->createDraft(NprFixtures::user(12, Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER], 'janek@tricab.com'), [], null)->request->requesterBranch);
        self::assertNotSame('', $known->request->requesterBranch);
        self::assertSame('unassigned', $unknown->request->requesterBranch);
    }

    public function test_a_body_that_is_not_an_npr_is_refused(): void
    {
        $service = NprFixtures::service($this->repository);

        $this->expectException(InvalidNprInput::class);
        $service->createDraft(NprFixtures::requester(), ['a', 'list'], null);
    }

    public function test_a_variant_gets_an_id_and_label_when_missing(): void
    {
        $view = NprFixtures::service($this->repository)->createDraft(NprFixtures::requester(), [], [['answers' => []], ['id' => 'x', 'label' => 'Second']]);

        self::assertSame('var_1', $view->request->variants[0]['id']);
        self::assertSame('Variant 1', $view->request->variants[0]['label']);
        self::assertSame('x', $view->request->variants[1]['id']);
    }

    public function test_saving_a_draft_rewrites_its_revision_in_place(): void
    {
        $service = NprFixtures::service($this->repository);
        $user = NprFixtures::requester();
        $created = $service->createDraft($user, [], null)->request;

        $saved = $service->saveDraft($user, $created->publicId, $created->version, NprFixtures::validRequestAnswers(), NprFixtures::validVariants(), 'Completed all requirements');

        self::assertNotNull($saved);
        self::assertSame(1, $saved->request->revision);
        self::assertSame([1], $this->repository->revisionNumbers($created->publicId));
        self::assertSame('BHP', $saved->request->customerName);
        self::assertNotSame($created->version, $saved->request->version);
    }

    public function test_a_save_on_a_stale_version_is_a_conflict(): void
    {
        $service = NprFixtures::service($this->repository);
        $user = NprFixtures::requester();
        $created = $service->createDraft($user, [], null)->request;
        $service->saveDraft($user, $created->publicId, $created->version, [], [], null);

        $this->expectException(NprRequestConflict::class);
        $service->saveDraft($user, $created->publicId, $created->version, [], [], null);
    }

    public function test_amending_a_returned_request_makes_a_new_revision(): void
    {
        $this->repository->withRequest(NprFixtures::record(self::OTHER, 10, RequestStatus::ReturnedToSales, 2));
        $service = NprFixtures::service($this->repository);

        $saved = $service->saveDraft(NprFixtures::requester(), self::OTHER, '0x00000000000000AA', [], [], null);

        self::assertNotNull($saved);
        self::assertSame(3, $saved->request->revision);
        self::assertSame([2, 3], $this->repository->revisionNumbers(self::OTHER), 'the earlier revision stays');
    }

    public function test_a_request_past_sales_cannot_be_edited(): void
    {
        foreach ([RequestStatus::AwaitingManager, RequestStatus::WithTsd, RequestStatus::AwaitingScreening, RequestStatus::ScreeningCompleted, RequestStatus::Cancelled] as $status) {
            $repository = new InMemoryNprRequestRepository();
            $repository->withRequest(NprFixtures::record(self::OTHER, 10, $status));
            try {
                NprFixtures::service($repository)->saveDraft(NprFixtures::requester(), self::OTHER, '0x00000000000000AA', [], [], null);
                self::fail($status->value . ' was editable.');
            } catch (NprRequestNotEditable) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_somebody_elses_request_is_not_found_to_read_or_save(): void
    {
        $this->repository->withRequest(NprFixtures::record(self::OTHER, 99, RequestStatus::Draft));
        $service = NprFixtures::service($this->repository);

        self::assertNull($service->getOwn(NprFixtures::requester(), self::OTHER));
        self::assertNull($service->saveDraft(NprFixtures::requester(), self::OTHER, '0x00000000000000AA', [], [], null));
        self::assertSame([], $service->listOwn(NprFixtures::requester()));
    }

    public function test_the_list_is_the_callers_own_only(): void
    {
        $service = NprFixtures::service($this->repository);
        $service->createDraft(NprFixtures::requester(10), [], null);
        $service->createDraft(NprFixtures::requester(11), [], null);

        self::assertCount(1, $service->listOwn(NprFixtures::requester(10)));
    }

    // ------------------------------------------------------------ allowedActions

    /** @return array<string, array{0: RequestStatus, 1: list<string>}> */
    public static function ownerActions(): array
    {
        return [
            'draft' => [RequestStatus::Draft, ['read', 'edit', 'submit']],
            'returned' => [RequestStatus::ReturnedToSales, ['read', 'edit', 'submit']],
            'awaiting manager' => [RequestStatus::AwaitingManager, ['read']],
            'with tsd' => [RequestStatus::WithTsd, ['read']],
            'completed' => [RequestStatus::ScreeningCompleted, ['read']],
            'cancelled' => [RequestStatus::Cancelled, ['read']],
        ];
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('ownerActions')]
    public function test_the_owner_s_allowed_actions_follow_status(RequestStatus $status, array $expected): void
    {
        $actions = (new NprAccessPolicy())->allowedActions(NprFixtures::requester(), NprFixtures::record(self::OTHER, 10, $status));

        self::assertSame($expected, $actions);
    }

    public function test_submit_needs_its_own_permission(): void
    {
        $user = NprFixtures::user(10, [Module::PERMISSION_SELF_READ, Module::PERMISSION_SELF_CREATE]);

        self::assertSame(['read', 'edit'], (new NprAccessPolicy())->allowedActions($user, NprFixtures::record(self::OTHER, 10, RequestStatus::Draft)));
    }

    public function test_a_reader_without_the_create_grant_cannot_edit(): void
    {
        $user = NprFixtures::user(10, [Module::PERMISSION_SELF_READ]);

        self::assertSame(['read'], (new NprAccessPolicy())->allowedActions($user, NprFixtures::record(self::OTHER, 10, RequestStatus::Draft)));
    }

    public function test_nobody_else_gets_any_action_on_a_request(): void
    {
        $policy = new NprAccessPolicy();

        self::assertSame([], $policy->allowedActions(NprFixtures::requester(11), NprFixtures::record(self::OTHER, 10, RequestStatus::Draft)));
        self::assertSame([], $policy->allowedActions(NprFixtures::tsd(), NprFixtures::record(self::OTHER, 10, RequestStatus::Draft)), 'TSD does not see drafts');
        self::assertSame([], $policy->allowedActions(NprFixtures::tsd(), NprFixtures::record(self::OTHER, 10, RequestStatus::AwaitingManager)));
    }

    public function test_tsd_may_read_a_request_that_has_reached_it_and_nothing_more_yet(): void
    {
        $policy = new NprAccessPolicy();

        foreach ([RequestStatus::WithTsd, RequestStatus::AwaitingScreening, RequestStatus::ScreeningCompleted] as $status) {
            self::assertSame(['read'], $policy->allowedActions(NprFixtures::tsd(), NprFixtures::record(self::OTHER, 10, $status)), $status->value);
        }
        self::assertSame([], $policy->allowedActions(NprFixtures::tsd(), NprFixtures::record(self::OTHER, 10, RequestStatus::ReturnedToSales)));
    }

    public function test_permission_codes_match_regardless_of_case(): void
    {
        $user = NprFixtures::user(10, ['npr.self.read']);

        self::assertTrue(NprAccessPolicy::holds($user, Module::PERMISSION_SELF_READ));
    }

    // ------------------------------------------------------------------ TSD

    public function test_the_tsd_list_has_requests_with_tsd_or_later(): void
    {
        $this->repository
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-000000000001', 10, RequestStatus::Draft))
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-000000000002', 10, RequestStatus::AwaitingManager))
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-000000000003', 10, RequestStatus::WithTsd))
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-000000000004', 11, RequestStatus::ScreeningCompleted))
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-000000000005', 10, RequestStatus::ReturnedToSales))
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-000000000006', 10, RequestStatus::Cancelled));
        $service = NprFixtures::service($this->repository);

        $ids = array_map(static fn ($view): string => substr($view->request->publicId, -1), $service->listForTsd(NprFixtures::tsd()));
        sort($ids);

        self::assertSame(['3', '4'], $ids);
        self::assertSame([], $service->listForTsd(NprFixtures::requester()), 'a requester has no TSD queue');
    }

    public function test_tsd_keeps_sight_of_a_request_it_received_and_returned(): void
    {
        $returnedByTsd = '00000000-0000-4000-8000-0000000000b1';
        $returnedByManager = '00000000-0000-4000-8000-0000000000b2';
        $this->repository
            ->withRequest(NprFixtures::record($returnedByTsd, 10, RequestStatus::ReturnedToSales, 2, [], [], new \DateTimeImmutable('2026-10-03 00:00:00')))
            ->withRequest(NprFixtures::record($returnedByManager, 10, RequestStatus::ReturnedToSales));
        $service = NprFixtures::service($this->repository);

        self::assertNotNull($service->getForTsd(NprFixtures::tsd(), $returnedByTsd));
        self::assertNull($service->getForTsd(NprFixtures::tsd(), $returnedByManager));
        self::assertSame([$returnedByTsd], array_map(static fn ($v): string => $v->request->publicId, $service->listForTsd(NprFixtures::tsd())));
        self::assertSame(['read', 'edit', 'submit'], (new NprAccessPolicy())->allowedActions(NprFixtures::requester(), NprFixtures::record($returnedByTsd, 10, RequestStatus::ReturnedToSales)));
        self::assertSame(['read'], (new NprAccessPolicy())->allowedActions(NprFixtures::tsd(), NprFixtures::record($returnedByTsd, 10, RequestStatus::ReturnedToSales, 2, [], [], new \DateTimeImmutable('2026-10-03'))));
    }

    public function test_a_tsd_reader_cannot_open_a_request_still_with_sales_or_a_manager(): void
    {
        $this->repository
            ->withRequest(NprFixtures::record(self::OTHER, 10, RequestStatus::AwaitingManager))
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-0000000000a2', 10, RequestStatus::WithTsd));
        $service = NprFixtures::service($this->repository);

        self::assertNull($service->getForTsd(NprFixtures::tsd(), self::OTHER));
        self::assertNotNull($service->getForTsd(NprFixtures::tsd(), '00000000-0000-4000-8000-0000000000a2'));
        self::assertNull($service->getForTsd(NprFixtures::requester(), '00000000-0000-4000-8000-0000000000a2'), 'the owner is not a TSD reader');
    }
}
