<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemSummary;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentReissue;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionSummary;
use TriCab\Core\Onboarding\Contract\OnboardingContentRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Service\SupersessionPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Test double and the container default, as the other Onboarding repositories
 * are. Holds assignments per version so a publish can be seen moving people.
 */
final class InMemoryOnboardingContentRepository implements OnboardingContentRepository
{
    /** @var array<string, ContentItemRecord> keyed "type:publicId" */
    private array $items = [];

    /** @var array<string, list<ContentVersionRecord>> keyed "type:publicId", oldest first */
    private array $versions = [];

    /** @var array<string, array{publishedAtUtc: ?string, retiredAtUtc: ?string}> keyed by version public id */
    private array $stamps = [];

    /**
     * @var list<array{publicId: string, userId: int, versionPublicId: string, status: string, withdrawn: bool, departed: bool}>
     */
    public array $assignments = [];

    /** @var list<OnboardingAdminEvent> */
    public array $writtenEvents = [];

    private int $nextId = 1;

    private int $token = 0;

    /**
     * Puts somebody on a version, as allocation would have.
     */
    public function withAssignment(string $versionPublicId, int $userId, string $status, bool $withdrawn = false, bool $departed = false): string
    {
        $publicId = sprintf('00000000-0000-4000-8000-%012d', count($this->assignments) + 1);
        $this->assignments[] = [
            'publicId' => $publicId,
            'userId' => $userId,
            'versionPublicId' => $versionPublicId,
            'status' => $status,
            'withdrawn' => $withdrawn,
            'departed' => $departed,
        ];

        return $publicId;
    }

    public function listItems(): array
    {
        $summaries = [];
        foreach ($this->items as $key => $item) {
            $live = null;
            $draft = null;
            foreach ($this->versions[$key] ?? [] as $version) {
                if ($version->state === ContentVersionSummary::STATE_LIVE) {
                    $live = $version;
                }
                if ($version->state === ContentVersionSummary::STATE_DRAFT) {
                    $draft = $version;
                }
            }
            $holders = 0;
            foreach ($this->versions[$key] ?? [] as $version) {
                foreach ($this->assignments as $assignment) {
                    if ($assignment['versionPublicId'] === $version->publicId && !$assignment['withdrawn']) {
                        $holders++;
                    }
                }
            }
            $summaries[] = new ContentItemSummary(
                $item->itemType,
                $item->publicId,
                $item->slug,
                $item->title,
                $item->accessGroup,
                $item->isActive,
                $live?->versionNumber,
                $draft?->publicId,
                $draft?->versionNumber,
                $holders,
            );
        }

        usort($summaries, static fn (ContentItemSummary $a, ContentItemSummary $b): int => [$a->itemType, $a->title, $a->publicId] <=> [$b->itemType, $b->title, $b->publicId]);

        return $summaries;
    }

    public function findItem(string $itemType, string $itemPublicId): ?ContentItemRecord
    {
        return $this->items[$itemType . ':' . $itemPublicId] ?? null;
    }

    public function listVersions(ContentItemRecord $item): array
    {
        $summaries = [];
        foreach (array_reverse($this->versions[self::key($item)] ?? []) as $version) {
            $unfinished = 0;
            $completed = 0;
            foreach ($this->assignments as $assignment) {
                if ($assignment['versionPublicId'] !== $version->publicId || $assignment['withdrawn']) {
                    continue;
                }
                $assignment['status'] === 'completed' ? $completed++ : $unfinished++;
            }
            $summaries[] = new ContentVersionSummary(
                $version->publicId,
                $version->versionNumber,
                $version->state,
                $this->stamps[$version->publicId]['publishedAtUtc'] ?? null,
                $version->state === ContentVersionSummary::STATE_DRAFT ? null : 'Test Author',
                $this->stamps[$version->publicId]['retiredAtUtc'] ?? null,
                $version->supersedesWorkInProgress,
                $unfinished,
                $completed,
            );
        }

        return $summaries;
    }

    public function findVersion(ContentItemRecord $item, string $versionPublicId): ?ContentVersionRecord
    {
        foreach ($this->versions[self::key($item)] ?? [] as $version) {
            if ($version->publicId === $versionPublicId) {
                return $version;
            }
        }

        return null;
    }

    public function latestPublishedVersion(ContentItemRecord $item): ?ContentVersionRecord
    {
        $latest = null;
        foreach ($this->versions[self::key($item)] ?? [] as $version) {
            if ($version->state === ContentVersionSummary::STATE_LIVE) {
                return $version;
            }
            if ($version->state === ContentVersionSummary::STATE_RETIRED) {
                $latest = $version;
            }
        }

        return $latest;
    }

    public function slugTaken(string $itemType, string $slug): bool
    {
        foreach ($this->items as $item) {
            if ($item->itemType === $itemType && $item->slug === $slug) {
                return true;
            }
        }

        return false;
    }

    public function createItem(
        string $itemType,
        string $itemPublicId,
        string $slug,
        string $title,
        ?string $summary,
        ?string $accessGroup,
        string $versionPublicId,
        AuthoredContent $initial,
        array $events,
        ActorContext $actor,
    ): void {
        if ($this->slugTaken($itemType, $slug)) {
            throw ContentAuthoringNotPermitted::slugTaken($slug);
        }
        $item = new ContentItemRecord($this->nextId++, $itemType, $itemPublicId, $slug, $title, $summary, $accessGroup, true, $this->nextToken());
        $this->items[self::key($item)] = $item;
        $this->versions[self::key($item)] = [$this->draft($versionPublicId, 1, $initial)];
        array_push($this->writtenEvents, ...$events);
    }

    public function updateItem(
        ContentItemRecord $item,
        RowVersionToken $expectedRowVersion,
        string $title,
        ?string $summary,
        bool $isActive,
        array $events,
        ActorContext $actor,
    ): void {
        $stored = $this->items[self::key($item)];
        if (!$stored->rowVersion->equals($expectedRowVersion)) {
            throw OnboardingWriteConflict::staleToken();
        }
        $this->items[self::key($item)] = new ContentItemRecord(
            $stored->itemId, $stored->itemType, $stored->publicId, $stored->slug, $title, $summary, $stored->accessGroup, $isActive, $this->nextToken(),
        );
        array_push($this->writtenEvents, ...$events);
    }

    public function createDraft(
        ContentItemRecord $item,
        string $versionPublicId,
        AuthoredContent $content,
        array $events,
        ActorContext $actor,
    ): int {
        $versions = $this->versions[self::key($item)] ?? [];
        foreach ($versions as $version) {
            if ($version->isDraft()) {
                throw ContentAuthoringNotPermitted::draftExists();
            }
        }
        $number = count($versions) + 1;
        $this->versions[self::key($item)][] = $this->draft($versionPublicId, $number, $content);
        array_push($this->writtenEvents, ...$events);

        return $number;
    }

    public function saveDraft(
        ContentItemRecord $item,
        ContentVersionRecord $version,
        RowVersionToken $expectedRowVersion,
        AuthoredContent $content,
        array $events,
        ActorContext $actor,
    ): void {
        $this->replace($item, $version->publicId, function (ContentVersionRecord $stored) use ($expectedRowVersion, $content): ContentVersionRecord {
            if (!$stored->isDraft()) {
                throw ContentAuthoringNotPermitted::versionPublished();
            }
            if (!$stored->rowVersion->equals($expectedRowVersion)) {
                throw OnboardingWriteConflict::staleToken();
            }

            return new ContentVersionRecord(
                $stored->versionId, $stored->publicId, $stored->versionNumber, $stored->state, $this->nextToken(),
                null, false, self::withQuestionIds($content, $stored->publicId),
            );
        });
        array_push($this->writtenEvents, ...$events);
    }

    public function publish(
        ContentItemRecord $item,
        ContentVersionRecord $version,
        RowVersionToken $expectedRowVersion,
        bool $supersedesWorkInProgress,
        callable $eventsFor,
        ActorContext $actor,
    ): array {
        $stored = $this->findVersion($item, $version->publicId);
        if ($stored === null || !$stored->isDraft()) {
            throw ContentAuthoringNotPermitted::versionPublished();
        }
        if (!$stored->rowVersion->equals($expectedRowVersion)) {
            throw OnboardingWriteConflict::staleToken();
        }

        $retired = null;
        foreach ($this->versions[self::key($item)] as $candidate) {
            if ($candidate->state === ContentVersionSummary::STATE_LIVE) {
                $retired = $candidate;
            }
        }

        $now = gmdate('Y-m-d\TH:i:s');
        if ($retired !== null) {
            $this->replace($item, $retired->publicId, fn (ContentVersionRecord $v): ContentVersionRecord => $this->withState($v, ContentVersionSummary::STATE_RETIRED, $v->supersedesWorkInProgress));
            $this->stamps[$retired->publicId]['retiredAtUtc'] = $now;
        }
        $this->replace($item, $version->publicId, fn (ContentVersionRecord $v): ContentVersionRecord => $this->withState($v, ContentVersionSummary::STATE_LIVE, $supersedesWorkInProgress));
        $this->stamps[$version->publicId] = ['publishedAtUtc' => $now, 'retiredAtUtc' => null];

        $reissued = [];
        if ($supersedesWorkInProgress && $retired !== null) {
            foreach ($this->assignments as $index => $assignment) {
                if ($assignment['versionPublicId'] !== $retired->publicId || $assignment['departed']) {
                    continue;
                }
                if (!SupersessionPolicy::shouldReissue(true, AssignmentStatus::fromStorage($assignment['status']), $assignment['withdrawn'])) {
                    continue;
                }
                $this->assignments[$index]['withdrawn'] = true;
                $newPublicId = $this->withAssignment($version->publicId, $assignment['userId'], SupersessionPolicy::statusAfterReissue()->toString());
                $reissued[] = new ContentReissue($assignment['userId'], $assignment['publicId'], $newPublicId, $assignment['status']);
            }
        }

        array_push($this->writtenEvents, ...$eventsFor($reissued, $retired?->versionNumber));

        return ['retiredVersionNumber' => $retired?->versionNumber, 'reissued' => $reissued];
    }

    // ----------------------------------------------------------------- helpers

    private function draft(string $publicId, int $number, AuthoredContent $content): ContentVersionRecord
    {
        return new ContentVersionRecord($this->nextId++, $publicId, $number, ContentVersionSummary::STATE_DRAFT, $this->nextToken(), null, false, self::withQuestionIds($content, $publicId));
    }

    private function withState(ContentVersionRecord $version, string $state, bool $supersedes): ContentVersionRecord
    {
        return new ContentVersionRecord(
            $version->versionId, $version->publicId, $version->versionNumber, $state, $this->nextToken(),
            $version->publishedAtUtc ?? gmdate('Y-m-d\TH:i:s'), $supersedes, $version->content,
        );
    }

    /**
     * @param callable(ContentVersionRecord): ContentVersionRecord $change
     */
    private function replace(ContentItemRecord $item, string $versionPublicId, callable $change): void
    {
        foreach ($this->versions[self::key($item)] as $index => $version) {
            if ($version->publicId === $versionPublicId) {
                $this->versions[self::key($item)][$index] = $change($version);

                return;
            }
        }
    }

    /** Questions get ids on storage, as the Azure repository gives them rows. */
    private static function withQuestionIds(AuthoredContent $content, string $versionPublicId): AuthoredContent
    {
        $questions = [];
        foreach ($content->questions as $index => $question) {
            $question['publicId'] = substr(md5($versionPublicId . ':' . $index), 0, 8) . '-0000-4000-8000-000000000000';
            $questions[] = $question;
        }

        return AuthoredContent::fromStorage(
            $content->itemType,
            $content->slides,
            $content->passMarkPercent,
            $questions,
            $content->blocks,
            $content->declaration,
            $content->effectiveFrom,
            $content->fields,
            $content->classifications,
        );
    }

    private function nextToken(): RowVersionToken
    {
        return RowVersionToken::fromBinary(pack('J', ++$this->token));
    }

    private static function key(ContentItemRecord $item): string
    {
        return $item->itemType . ':' . $item->publicId;
    }
}
