<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemDetail;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionRecord;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingContentRepository;

/**
 * The authoring lifecycle (4.4): create, draft, save, publish, supersede.
 * In memory; the SQL is exercised by `AzureSqlOnboardingContentRepositoryTest`.
 */
final class ContentAuthoringTest extends TestCase
{
    private const HR = 7;

    private InMemoryOnboardingContentRepository $content;

    private InMemoryOnboardingAdminAuditLog $audit;

    private AuthorOnboardingContentService $service;

    protected function setUp(): void
    {
        $this->content = new InMemoryOnboardingContentRepository();
        $this->audit = new InMemoryOnboardingAdminAuditLog();
        $this->service = new AuthorOnboardingContentService($this->content, $this->audit, new UuidV4Generator());
    }

    public function test_a_new_item_starts_as_an_empty_draft_that_cannot_be_published(): void
    {
        $detail = $this->createModule();
        $version = $this->onlyVersion($detail);

        self::assertTrue($version->isDraft());
        self::assertSame(1, $version->versionNumber);
        self::assertSame(80, $version->content->passMarkPercent, 'the source default');
        self::assertSame([
            'Add at least one slide.',
            'Add at least one question: passing the assessment is how a module is completed.',
        ], $version->content->publishProblems(null));

        try {
            $this->service->publish($this->actor(), 'training', $detail->item->publicId, $version->publicId, $version->rowVersion, false);
            self::fail('An empty module was published.');
        } catch (ContentAuthoringNotPermitted $refused) {
            self::assertSame(ContentAuthoringNotPermitted::REASON_NOT_PUBLISHABLE, $refused->reason);
        }

        $refusal = end($this->audit->entries);
        self::assertNotFalse($refusal);
        self::assertSame(AdminEventType::CONTENT_REFUSED, $refusal['event']->eventType);
        self::assertSame('not_publishable', $refusal['event']->details['reason']);
    }

    public function test_a_draft_is_saved_published_and_frozen(): void
    {
        $detail = $this->createModule();
        $draft = $this->onlyVersion($detail);

        $saved = $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $draft->publicId, $draft->rowVersion, self::moduleBody());
        self::assertSame([], $saved->content->publishProblems(null));
        self::assertSame('b', $saved->content->questions[0]['correctOptionKey']);

        $published = $this->service->publish($this->actor(), 'training', $detail->item->publicId, $saved->publicId, $saved->rowVersion, false);
        self::assertSame('live', $published['version']->state);
        self::assertNull($published['retiredVersionNumber']);

        try {
            $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $saved->publicId, $published['version']->rowVersion, self::moduleBody('Changed after publishing'));
            self::fail('A published version was edited.');
        } catch (ContentAuthoringNotPermitted $refused) {
            self::assertSame(ContentAuthoringNotPermitted::REASON_VERSION_PUBLISHED, $refused->reason);
        }

        $types = array_map(static fn (OnboardingAdminEvent $e): string => $e->eventType, $this->content->writtenEvents);
        self::assertSame([AdminEventType::CONTENT_ITEM_CREATED, AdminEventType::CONTENT_DRAFT_SAVED, AdminEventType::CONTENT_PUBLISHED], $types);

        $saveEvent = $this->content->writtenEvents[1];
        self::assertSame(['itemType' => 'training', 'itemPublicId' => $detail->item->publicId, 'versionNumber' => 1, 'slideCount' => 2, 'questionCount' => 1], $saveEvent->details);
        self::assertStringNotContainsString('walkways', (string) $saveEvent->detailsJson(), 'no content in the audit row');
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $detail = $this->createModule();
        $draft = $this->onlyVersion($detail);
        $saved = $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $draft->publicId, $draft->rowVersion, self::moduleBody());
        $events = count($this->content->writtenEvents);

        $again = $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $saved->publicId, $saved->rowVersion, self::moduleBody());

        self::assertTrue($again->rowVersion->equals($saved->rowVersion), 'the token did not move');
        self::assertCount($events, $this->content->writtenEvents);
    }

    public function test_a_stale_token_is_a_conflict(): void
    {
        $detail = $this->createModule();
        $draft = $this->onlyVersion($detail);
        $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $draft->publicId, $draft->rowVersion, self::moduleBody());

        $this->expectException(OnboardingWriteConflict::class);
        $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $draft->publicId, $draft->rowVersion, self::moduleBody('Second author'));
    }

    public function test_a_new_draft_copies_the_live_version_and_only_one_draft_exists(): void
    {
        [$itemPublicId] = $this->publishedModule();

        $draft = $this->service->createDraft($this->actor(), 'training', $itemPublicId);
        self::assertSame(2, $draft->versionNumber);
        self::assertSame('Where may you walk while a forklift is operating?', $draft->content->questions[0]['prompt']);

        try {
            $this->service->createDraft($this->actor(), 'training', $itemPublicId);
            self::fail('A second draft was started.');
        } catch (ContentAuthoringNotPermitted $refused) {
            self::assertSame(ContentAuthoringNotPermitted::REASON_DRAFT_EXISTS, $refused->reason);
        }
    }

    public function test_a_minor_revision_moves_nobody(): void
    {
        [$itemPublicId, $v1] = $this->publishedModule();
        $this->content->withAssignment($v1, 101, 'in_progress');

        $result = $this->publishNewVersion($itemPublicId, false);

        self::assertSame(1, $result['retiredVersionNumber']);
        self::assertSame(0, $result['reissuedCount']);
        self::assertSame('in_progress', $this->content->assignments[0]['status']);
        self::assertFalse($this->content->assignments[0]['withdrawn']);
    }

    public function test_a_superseding_version_moves_unfinished_work_and_leaves_the_rest(): void
    {
        [$itemPublicId, $v1] = $this->publishedModule();
        $this->content->withAssignment($v1, 101, 'in_progress');
        $this->content->withAssignment($v1, 102, 'submitted');
        $this->content->withAssignment($v1, 103, 'completed');
        $this->content->withAssignment($v1, 104, 'not_started', withdrawn: true);
        $this->content->withAssignment($v1, 105, 'in_progress', departed: true);

        $result = $this->publishNewVersion($itemPublicId, true);

        self::assertSame(2, $result['reissuedCount']);
        $byUser = [];
        foreach ($this->content->assignments as $a) {
            $byUser[$a['userId']][] = $a;
        }
        self::assertTrue($byUser[101][0]['withdrawn']);
        self::assertSame('not_started', $byUser[101][1]['status']);
        self::assertSame($result['version']->publicId, $byUser[101][1]['versionPublicId']);
        self::assertCount(2, $byUser[102], 'submitted is not final, so it moves');
        self::assertCount(1, $byUser[103], 'completed is never reissued');
        self::assertFalse($byUser[103][0]['withdrawn']);
        self::assertCount(1, $byUser[104], 'withdrawn is nobody’s work');
        self::assertCount(1, $byUser[105], 'a leaver is not given new work');

        $reissues = array_values(array_filter($this->content->writtenEvents, static fn (OnboardingAdminEvent $e): bool => $e->eventType === AdminEventType::ALLOCATION_REISSUED));
        self::assertSame([101, 102], array_map(static fn (OnboardingAdminEvent $e): ?int => $e->subjectUserId, $reissues));
        self::assertSame('in_progress', $reissues[0]->details['fromStatus']);
        self::assertSame('not_started', $reissues[0]->details['toStatus']);
    }

    public function test_a_taken_slug_is_refused_and_audited(): void
    {
        $this->createModule();

        try {
            $this->createModule();
            self::fail('A duplicate slug was accepted.');
        } catch (ContentAuthoringNotPermitted $refused) {
            self::assertSame(ContentAuthoringNotPermitted::REASON_SLUG_TAKEN, $refused->reason);
        }
        self::assertSame(AdminEventType::CONTENT_REFUSED, $this->audit->entries[0]['event']->eventType);
    }

    public function test_a_payroll_form_publishes_only_when_every_field_is_classified(): void
    {
        $detail = $this->service->createItem($this->actor(), 'form', ['slug' => 'bank-details', 'title' => 'Bank details', 'accessGroup' => 'payroll']);
        $draft = $this->onlyVersion($detail);

        try {
            $this->service->saveDraft($this->actor(), 'form', $detail->item->publicId, $draft->publicId, $draft->rowVersion, [
                'fields' => [['path' => 'bank.bsb', 'label' => 'BSB', 'type' => 'text']],
            ]);
            self::fail('An unclassified payroll field was saved.');
        } catch (InvalidOnboardingValue) {
            self::addToAssertionCount(1);
        }

        $saved = $this->service->saveDraft($this->actor(), 'form', $detail->item->publicId, $draft->publicId, $draft->rowVersion, [
            'fields' => [
                ['path' => 'bank.bsb', 'label' => 'BSB', 'type' => 'text', 'required' => true, 'dataClass' => DataClass::PAYROLL],
                ['path' => 'confirm', 'label' => 'Correct', 'type' => 'checkbox', 'dataClass' => DataClass::ORDINARY_HR],
            ],
        ]);
        self::assertSame(['bank.bsb' => DataClass::PAYROLL, 'confirm' => DataClass::ORDINARY_HR], $saved->content->classifications);
        self::assertSame(DataClass::PAYROLL, $saved->content->toArray()['fields'][0]['dataClass'] ?? null);

        $published = $this->service->publish($this->actor(), 'form', $detail->item->publicId, $saved->publicId, $saved->rowVersion, false);
        self::assertSame('live', $published['version']->state);
    }

    public function test_a_policy_keeps_its_blocks_declaration_and_date(): void
    {
        $detail = $this->service->createItem($this->actor(), 'policy', ['slug' => 'whs', 'title' => 'Workplace health and safety']);
        $draft = $this->onlyVersion($detail);

        $saved = $this->service->saveDraft($this->actor(), 'policy', $detail->item->publicId, $draft->publicId, $draft->rowVersion, [
            'blocks' => [['type' => 'heading', 'text' => 'Purpose'], ['type' => 'callout', 'tone' => 'warning', 'text' => 'Stop if unsafe.']],
            'declaration' => 'I will work safely.',
            'effectiveFrom' => '2026-10-01',
        ]);

        self::assertSame('I will work safely.', $saved->content->declaration);
        self::assertSame('2026-10-01', $saved->content->effectiveFrom);
        self::assertSame(2, $saved->content->blocks[0]['level']);
    }

    public function test_a_policy_version_keeps_its_reference_labels_emphasis_and_acknowledgement_choice(): void
    {
        $detail = $this->service->createItem($this->actor(), 'policy', ['slug' => 'leave', 'title' => 'Leave Policy']);
        $draft = $this->onlyVersion($detail);
        self::assertTrue($draft->content->requiresAcknowledgement, 'A new policy is acknowledged unless its author says otherwise.');

        $saved = $this->service->saveDraft($this->actor(), 'policy', $detail->item->publicId, $draft->publicId, $draft->rowVersion, [
            'blocks' => [['type' => 'paragraph', 'text' => 'Annual leave: four weeks.', 'marks' => ['text' => [['start' => 0, 'end' => 13, 'mark' => 'bold']]]]],
            'documentReference' => ' HR-POL-034-V000 ',
            'issuedLabel' => 'September-25',
            'reviewDueLabel' => 'September-28',
            'requiresAcknowledgement' => false,
        ]);

        self::assertSame('HR-POL-034-V000', $saved->content->documentReference);
        self::assertSame('September-25', $saved->content->issuedLabel);
        self::assertSame('September-28', $saved->content->reviewDueLabel);
        self::assertFalse($saved->content->requiresAcknowledgement);
        self::assertSame(['text' => [['start' => 0, 'end' => 13, 'mark' => 'bold']]], $saved->content->blocks[0]['marks']);

        // A new draft starts as a copy of the live version, metadata and all.
        $published = $this->service->publish($this->actor(), 'policy', $detail->item->publicId, $saved->publicId, $saved->rowVersion, false);
        $next = $this->service->createDraft($this->actor(), 'policy', $detail->item->publicId);
        self::assertTrue($next->content->sameAs($published['version']->content));
    }

    public function test_changing_only_whether_a_policy_needs_acknowledging_is_a_change(): void
    {
        $detail = $this->service->createItem($this->actor(), 'policy', ['slug' => 'dress', 'title' => 'Dress Code']);
        $draft = $this->onlyVersion($detail);
        $body = ['blocks' => [['type' => 'paragraph', 'text' => 'Wear boots.']]];
        $saved = $this->service->saveDraft($this->actor(), 'policy', $detail->item->publicId, $draft->publicId, $draft->rowVersion, $body);

        $again = $this->service->saveDraft($this->actor(), 'policy', $detail->item->publicId, $saved->publicId, $saved->rowVersion, $body + ['requiresAcknowledgement' => false]);

        self::assertNotSame($saved->rowVersion->toBase64(), $again->rowVersion->toBase64());
    }

    public function test_a_policy_s_summary_chapter_and_position_are_item_edits_that_a_body_without_them_leaves_alone(): void
    {
        $detail = $this->service->createItem($this->actor(), 'policy', [
            'slug' => 'leave-policy',
            'title' => 'Leave Policy',
            'summary' => 'Annual, personal and unpaid leave.',
            'chapterTitle' => 'Working at TriCab',
            'sortOrder' => 7,
        ]);
        self::assertSame('Annual, personal and unpaid leave.', $detail->item->summary);
        self::assertSame('Working at TriCab', $detail->item->chapterTitle);
        self::assertSame(7, $detail->item->sortOrder);

        // An older client that knows only title and isActive.
        $renamed = $this->service->updateItem($this->actor(), 'policy', $detail->item->publicId, $detail->item->rowVersion, ['title' => 'Leave', 'isActive' => true]);
        self::assertSame('Working at TriCab', $renamed->item->chapterTitle);
        self::assertSame(7, $renamed->item->sortOrder);
        self::assertSame('Annual, personal and unpaid leave.', $renamed->item->summary);

        $moved = $this->service->updateItem($this->actor(), 'policy', $renamed->item->publicId, $renamed->item->rowVersion, ['title' => 'Leave', 'sortOrder' => 8, 'chapterTitle' => '']);
        self::assertSame(8, $moved->item->sortOrder);
        self::assertNull($moved->item->chapterTitle);
        $event = end($this->content->writtenEvents);
        self::assertNotFalse($event);
        self::assertSame(['chapterTitle', 'sortOrder'], $event->details['changedFields']);
    }

    public function test_a_policy_position_must_be_a_whole_number_from_one(): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        $this->service->createItem($this->actor(), 'policy', ['slug' => 'x', 'title' => 'X', 'sortOrder' => 0]);
    }

    public function test_deactivating_an_item_is_an_audited_edit(): void
    {
        $detail = $this->createModule();

        $updated = $this->service->updateItem($this->actor(), 'training', $detail->item->publicId, $detail->item->rowVersion, [
            'title' => $detail->item->title,
            'summary' => null,
            'isActive' => false,
        ]);

        self::assertFalse($updated->item->isActive);
        $event = end($this->content->writtenEvents);
        self::assertNotFalse($event);
        self::assertSame(AdminEventType::CONTENT_ITEM_UPDATED, $event->eventType);
        self::assertSame(['isActive'], $event->details['changedFields']);
    }

    // ---------------------------------------------------------------- helpers

    private function createModule(): ContentItemDetail
    {
        return $this->service->createItem($this->actor(), 'training', ['slug' => 'forklift-awareness', 'title' => 'Forklift awareness']);
    }

    /**
     * @return array{0: string, 1: string} item and live version public ids
     */
    private function publishedModule(): array
    {
        $detail = $this->createModule();
        $draft = $this->onlyVersion($detail);
        $saved = $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $draft->publicId, $draft->rowVersion, self::moduleBody());
        $this->service->publish($this->actor(), 'training', $detail->item->publicId, $saved->publicId, $saved->rowVersion, false);

        return [$detail->item->publicId, $saved->publicId];
    }

    /**
     * @return array{version: ContentVersionRecord, retiredVersionNumber: ?int, reissuedCount: int}
     */
    private function publishNewVersion(string $itemPublicId, bool $supersedes): array
    {
        $draft = $this->service->createDraft($this->actor(), 'training', $itemPublicId);
        $saved = $this->service->saveDraft($this->actor(), 'training', $itemPublicId, $draft->publicId, $draft->rowVersion, self::moduleBody('Where must you never walk?'));

        return $this->service->publish($this->actor(), 'training', $itemPublicId, $saved->publicId, $saved->rowVersion, $supersedes);
    }

    private function onlyVersion(ContentItemDetail $detail): ContentVersionRecord
    {
        $version = $this->content->findVersion($detail->item, $detail->versions[0]->publicId);
        self::assertNotNull($version);

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private static function moduleBody(string $prompt = 'Where may you walk while a forklift is operating?'): array
    {
        return [
            'slides' => [['id' => 'intro', 'title' => 'Why forklifts are dangerous'], ['id' => 'zones', 'title' => 'Pedestrian zones']],
            'passMarkPercent' => 100,
            'questions' => [[
                'prompt' => $prompt,
                'options' => [['key' => 'a', 'label' => 'Anywhere'], ['key' => 'b', 'label' => 'Only in the marked walkways']],
                'correctOptionKey' => 'b',
                'correctReason' => 'Walkways are where a driver expects a person to be.',
            ]],
        ];
    }

    private function actor(): ActorContext
    {
        return new ActorContext(self::HR, 'hr-public-id', 'hr@example.test');
    }
}
