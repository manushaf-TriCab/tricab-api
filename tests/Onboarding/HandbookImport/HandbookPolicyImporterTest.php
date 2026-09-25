<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding\HandbookImport;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\HandbookImport\HandbookPolicyImporter;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingContentRepository;

final class HandbookPolicyImporterTest extends TestCase
{
    private InMemoryOnboardingContentRepository $content;

    private HandbookPolicyImporter $importer;

    protected function setUp(): void
    {
        $this->content = new InMemoryOnboardingContentRepository();
        $service = new AuthorOnboardingContentService($this->content, new InMemoryOnboardingAdminAuditLog(), new UuidV4Generator());
        $this->importer = new HandbookPolicyImporter($this->content, $service);
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(string $slug, string $text = 'Wear the boots.', bool $acknowledge = false, int $order = 1): array
    {
        return [
            'schema' => HandbookPolicyImporter::SCHEMA,
            'slug' => $slug,
            'title' => 'Synthetic ' . $slug,
            'summary' => 'Synthetic summary.',
            'metadata' => ['chapterTitle' => 'Working at TriCab', 'sortOrder' => $order],
            'content' => [
                'blocks' => [['type' => 'paragraph', 'text' => $text, 'marks' => ['text' => [['start' => 0, 'end' => 4, 'mark' => 'bold']]]]],
                'declaration' => $acknowledge ? 'I accept it.' : null,
                'effectiveFrom' => null,
                'documentReference' => 'SYN-POL-001-V000',
                'issuedLabel' => 'July-25',
                'reviewDueLabel' => 'July-27',
                'requiresAcknowledgement' => $acknowledge,
            ],
        ];
    }

    private static function actor(): ActorContext
    {
        return new ActorContext(7, '00000000-0000-4000-8000-000000000007', 'author@example.test');
    }

    public function test_a_dry_run_plans_and_writes_nothing(): void
    {
        $plan = $this->importer->plan([self::record('dress-code'), self::record('code-of-conduct', acknowledge: true, order: 3)]);

        self::assertSame([HandbookPolicyImporter::CREATE, HandbookPolicyImporter::CREATE], array_column($plan, 'action'));
        self::assertSame([], $this->content->listItems());
        self::assertSame([], $this->content->writtenEvents);
    }

    public function test_applying_creates_saves_and_publishes_each_through_the_service_with_audit_rows(): void
    {
        $results = $this->importer->apply($this->importer->plan([self::record('dress-code'), self::record('code-of-conduct', acknowledge: true, order: 3)]), self::actor(), false);

        self::assertSame(['created and published', 'created and published'], array_column($results, 'result'));
        self::assertSame([1, 1], array_column($results, 'versionNumber'));

        $items = $this->content->listItems();
        self::assertCount(2, $items);
        self::assertSame([1, 1], array_map(static fn ($i): ?int => $i->liveVersionNumber, $items));

        $item = $this->content->findItem('policy', $items[0]->publicId);
        self::assertNotNull($item);
        $live = $this->content->latestPublishedVersion($item);
        self::assertNotNull($live);
        self::assertSame('SYN-POL-001-V000', $live->content->documentReference);
        self::assertArrayHasKey('marks', $live->content->blocks[0]);
        self::assertSame('Working at TriCab', $item->chapterTitle);

        // Created, saved, published — for each policy, attributed to the actor.
        self::assertSame(
            [AdminEventType::CONTENT_ITEM_CREATED, AdminEventType::CONTENT_DRAFT_SAVED, AdminEventType::CONTENT_PUBLISHED],
            array_values(array_unique(array_map(static fn ($e): string => $e->eventType, $this->content->writtenEvents))),
        );
        self::assertCount(6, $this->content->writtenEvents);
    }

    public function test_a_second_run_changes_nothing_and_writes_nothing(): void
    {
        $records = [self::record('dress-code'), self::record('code-of-conduct', acknowledge: true, order: 3)];
        $this->importer->apply($this->importer->plan($records), self::actor(), true);
        $written = count($this->content->writtenEvents);

        $plan = $this->importer->plan($records);
        self::assertSame([HandbookPolicyImporter::UNCHANGED, HandbookPolicyImporter::UNCHANGED], array_column($plan, 'action'));
        self::assertSame([[], []], array_column($plan, 'itemChanges'));

        $this->importer->apply($plan, self::actor(), true);
        self::assertCount($written, $this->content->writtenEvents);
    }

    public function test_changed_wording_becomes_a_draft_and_is_published_only_when_asked(): void
    {
        $this->importer->apply($this->importer->plan([self::record('leave')]), self::actor(), false);

        $plan = $this->importer->plan([self::record('leave', 'Wear the boots at all times.', order: 7)]);
        self::assertSame(HandbookPolicyImporter::CHANGE, $plan[0]['action']);
        self::assertSame(['sortOrder'], $plan[0]['itemChanges']);

        $results = $this->importer->apply($plan, self::actor(), false);
        self::assertSame('saved as a draft, not published', $results[0]['result']);
        self::assertSame(2, $results[0]['versionNumber']);
        self::assertSame(1, $this->content->listItems()[0]->liveVersionNumber, 'the live version is untouched');
        self::assertSame(7, $this->content->findItem('policy', $this->content->listItems()[0]->publicId)?->sortOrder);

        // The open draft now blocks another run rather than being written over.
        $again = $this->importer->plan([self::record('leave', 'Something else.')]);
        self::assertSame(HandbookPolicyImporter::BLOCKED, $again[0]['action']);
        self::assertSame('nothing written', $this->importer->apply($again, self::actor(), true)[0]['result']);
    }

    public function test_publishing_changes_publishes_the_new_version_without_superseding_work(): void
    {
        $this->importer->apply($this->importer->plan([self::record('leave')]), self::actor(), false);

        $results = $this->importer->apply($this->importer->plan([self::record('leave', 'New wording.')]), self::actor(), true);

        self::assertSame('published', $results[0]['result']);
        self::assertSame(2, $this->content->listItems()[0]->liveVersionNumber);
        $published = array_values(array_filter($this->content->writtenEvents, static fn ($e): bool => $e->eventType === AdminEventType::CONTENT_PUBLISHED));
        self::assertFalse(end($published)->details['supersedesWorkInProgress'] ?? true);
    }

    public function test_a_record_the_rules_refuse_stops_the_plan_before_anything_is_written(): void
    {
        $bad = self::record('bad');
        $bad['content'] = ['blocks' => [['type' => 'html', 'html' => '<script></script>']]];

        try {
            $this->importer->plan([self::record('good'), $bad]);
            self::fail('A block the rules refuse was planned.');
        } catch (InvalidOnboardingValue) {
        }
        self::assertSame([], $this->content->writtenEvents);
    }

    public function test_something_that_is_not_converter_output_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importer->plan([['slug' => 'x', 'content' => []]]);
    }
}
