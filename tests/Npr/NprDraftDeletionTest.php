<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Npr\Support\NprWorkflowWorld as W;
use TriCab\Core\Npr\Application\Service\NprAccessPolicy;
use TriCab\Core\Npr\Application\Service\NprDraftDeletionService;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotDeletable;
use TriCab\Core\Npr\Domain\NewNprAttachment;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Infrastructure\InMemoryNprAttachmentRepository;
use TriCab\Core\Npr\Infrastructure\InMemoryNprAttachmentStore;

/** Sales deleting a never-submitted draft: who, when, what goes with it. */
final class NprDraftDeletionTest extends TestCase
{
    private W $w;
    private InMemoryNprAttachmentStore $store;
    private InMemoryNprAttachmentRepository $files;
    private NprDraftDeletionService $service;

    protected function setUp(): void
    {
        $this->w = new W();
        $this->store = new InMemoryNprAttachmentStore();
        $this->files = new InMemoryNprAttachmentRepository($this->w->requests, $this->w->clock);
        $this->service = new NprDraftDeletionService($this->w->requests, $this->files, $this->store, new NprAccessPolicy());
    }

    private function draft(): string
    {
        return $this->w->drafts->createDraft($this->w->user(W::SALES), [], null)->request->publicId;
    }

    public function test_the_owner_deletes_a_never_submitted_draft_and_its_blobs(): void
    {
        $id = $this->draft();
        $this->store->put($id . '/live.pdf', 'application/pdf', 'x');
        $this->store->put($id . '/gone.pdf', 'application/pdf', 'y');
        $by = new NprPerson(W::SALES, 'amandaw@tricab.com', 'Amanda');
        $this->files->add($id, new NewNprAttachment('a.pdf', $id . '/live.pdf', 1, 'application/pdf', 'datasheet', 'a', $by), 'requester');
        $removed = $this->files->add($id, new NewNprAttachment('b.pdf', $id . '/gone.pdf', 1, 'application/pdf', 'datasheet', 'b', $by), 'requester');
        self::assertNotNull($removed);
        $this->files->softDelete($id, $removed->publicId, $by, 'requester');

        self::assertTrue($this->service->delete($this->w->user(W::SALES), $id));

        self::assertNull($this->w->requests->find($id));
        self::assertNull($this->store->get($id . '/live.pdf'));
        self::assertNull($this->store->get($id . '/gone.pdf'), 'soft-deleted files go too');
        self::assertFalse($this->service->delete($this->w->user(W::SALES), $id), 'gone is 404');
    }

    public function test_a_submitted_request_is_not_deletable(): void
    {
        [$id] = $this->w->submitted();

        $this->expectException(NprRequestNotDeletable::class);
        $this->service->delete($this->w->user(W::SALES), $id);
    }

    public function test_a_request_returned_to_sales_is_not_deletable(): void
    {
        [$id, $version] = $this->w->submitted();
        $this->w->workflow->managerReturn($this->w->user(W::SHANE), $id, $version, 'Needs more detail');
        $view = $this->w->drafts->getOwn($this->w->user(W::SALES), $id);

        self::assertSame('returned_to_sales', $view?->request->status->value);
        self::assertNotContains('delete', $view->allowedActions);
        $this->expectException(NprRequestNotDeletable::class);
        $this->service->delete($this->w->user(W::SALES), $id);
    }

    public function test_someone_elses_draft_is_not_found_and_survives(): void
    {
        $id = $this->draft();

        self::assertFalse($this->service->delete($this->w->user(W::TSD), $id));
        self::assertFalse($this->service->delete($this->w->user(W::SALES), '99999999-9999-4999-8999-999999999999'));
        self::assertNotNull($this->w->requests->find($id));
    }

    public function test_allowed_actions_carry_delete_only_for_the_owner_of_a_draft(): void
    {
        $id = $this->draft();
        $request = $this->w->requests->find($id);
        $policy = new NprAccessPolicy();

        self::assertNotNull($request);
        self::assertContains('delete', $policy->allowedActions($this->w->user(W::SALES), $request));
        self::assertNotContains('delete', $policy->allowedActions($this->w->user(W::TSD), $request));
        [$submitted] = $this->w->submitted();
        self::assertNotContains('delete', $policy->allowedActions($this->w->user(W::SALES), $this->w->requests->find($submitted) ?? $request));
    }
}
