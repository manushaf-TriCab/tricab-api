<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemDetail;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentReissue;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionRecord;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingContentRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingContentNotFound;
use TriCab\Core\Onboarding\Domain\Service\FormVersionPublishGuard;
use TriCab\Core\Onboarding\Domain\Service\SupersessionPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * HR writes training modules, policies and forms (4.4).
 *
 * ## Versions, not edits
 *
 * The source edited a live module in place, with no transaction, while people
 * were part-way through it. Here content is only ever written to a **draft**:
 *
 *  1. creating an item makes it with an empty draft, version 1;
 *  2. a draft is saved as often as the author likes, and nobody sees it;
 *  3. publishing freezes it, retires the version it replaces, and — only if the
 *     author ticks "supersedes work in progress" — moves people part-way through
 *     the old version onto it ({@see SupersessionPolicy});
 *  4. changing a published version means starting a new draft, which begins as
 *     a copy of the live one.
 *
 * One draft per item at a time. Two drafts would mean two authors each
 * believing theirs is the next version.
 *
 * ## What is audited
 *
 * Every write, with counts — slides, questions, blocks, fields — and never the
 * wording, which is content rather than evidence and is kept on the version row
 * itself. A refusal that the content's state explains (a published version, a
 * second draft, a taken slug, an incomplete draft) is audited on its own. A
 * stale token is not: it is two authors racing, and the retry is the record.
 */
final readonly class AuthorOnboardingContentService
{
    public const PERMISSION = 'ONBOARDING.CONTENT.MANAGE';

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const MAX_SLUG_LENGTH = 120;
    private const MAX_TITLE_LENGTH = 240;
    private const MAX_SUMMARY_LENGTH = 1000;
    private const MAX_CHAPTER_LENGTH = 120;
    private const MAX_SORT_ORDER = 10000;
    private const ACCESS_GROUPS = [
        FormVersionPublishGuard::ACCESS_GROUP_HR,
        FormVersionPublishGuard::ACCESS_GROUP_PAYROLL,
        FormVersionPublishGuard::ACCESS_GROUP_RESTRICTED_HR,
    ];

    public function __construct(
        private OnboardingContentRepository $content,
        private OnboardingAdminAuditLog $audit,
        private UuidV4Generator $ids,
    ) {
    }

    /**
     * @param array<string, mixed> $body `{slug, title, summary?, accessGroup?, chapterTitle?, sortOrder?}`
     */
    public function createItem(ActorContext $actor, string $itemType, array $body): ContentItemDetail
    {
        $type = self::type($itemType);
        $slug = self::slug($body['slug'] ?? null);
        $title = self::title($body['title'] ?? null);
        // Modules and policies have a summary column; a form ignores one.
        $summary = $type !== AuthoredContent::TYPE_FORM ? self::summary($body['summary'] ?? null) : null;
        $accessGroup = $type === AuthoredContent::TYPE_FORM ? self::accessGroup($body['accessGroup'] ?? null) : null;
        [$chapterTitle, $sortOrder] = $type === AuthoredContent::TYPE_POLICY
            ? [self::chapterTitle($body['chapterTitle'] ?? null), self::sortOrder($body['sortOrder'] ?? null)]
            : [null, null];

        if ($this->content->slugTaken($type, $slug)) {
            $this->refuse($actor, null, ContentAuthoringNotPermitted::slugTaken($slug), ['itemType' => $type]);
        }

        $itemPublicId = $this->ids->generate()->toString();
        $versionPublicId = $this->ids->generate()->toString();
        $initial = AuthoredContent::empty($type);

        $this->content->createItem($type, $itemPublicId, $slug, $title, $summary, $accessGroup, $chapterTitle, $sortOrder, $versionPublicId, $initial, [
            OnboardingAdminEvent::succeeded(
                AdminEventType::CONTENT_ITEM_CREATED,
                null,
                $itemPublicId,
                sprintf('Created a %s with an empty draft.', self::noun($type)),
                ['itemType' => $type, 'versionPublicId' => $versionPublicId, 'versionNumber' => 1, 'accessGroup' => $accessGroup],
                self::PERMISSION,
            ),
        ], $actor);

        return $this->detail($type, $itemPublicId);
    }

    /**
     * @param array<string, mixed> $body `{title, summary?, isActive, chapterTitle?, sortOrder?}`
     */
    public function updateItem(
        ActorContext $actor,
        string $itemType,
        string $itemPublicId,
        RowVersionToken $expectedRowVersion,
        array $body,
    ): ContentItemDetail {
        $item = $this->requireItem($itemType, $itemPublicId);
        $title = self::title($body['title'] ?? null);
        $summary = match ($item->itemType) {
            AuthoredContent::TYPE_TRAINING => self::summary($body['summary'] ?? null),
            // A policy's summary, chapter and position are newer than the
            // editor that sends this, so a body without them leaves them be.
            AuthoredContent::TYPE_POLICY => array_key_exists('summary', $body) ? self::summary($body['summary']) : $item->summary,
            default => null,
        };
        [$chapterTitle, $sortOrder] = $item->itemType === AuthoredContent::TYPE_POLICY
            ? [
                array_key_exists('chapterTitle', $body) ? self::chapterTitle($body['chapterTitle']) : $item->chapterTitle,
                array_key_exists('sortOrder', $body) ? self::sortOrder($body['sortOrder']) : $item->sortOrder,
            ]
            : [null, null];
        $isActive = $body['isActive'] ?? $item->isActive;
        if (!is_bool($isActive)) {
            throw InvalidOnboardingValue::for('isActive', '(not true or false)');
        }

        $changed = self::changed([
            'title' => $title !== $item->title,
            'summary' => $summary !== $item->summary,
            'chapterTitle' => $chapterTitle !== $item->chapterTitle,
            'sortOrder' => $sortOrder !== $item->sortOrder,
            'isActive' => $isActive !== $item->isActive,
        ]);

        if ($changed !== []) {
            $this->content->updateItem($item, $expectedRowVersion, $title, $summary, $chapterTitle, $sortOrder, $isActive, [
                OnboardingAdminEvent::succeeded(
                    AdminEventType::CONTENT_ITEM_UPDATED,
                    null,
                    $item->publicId,
                    in_array('isActive', $changed, true)
                        ? ($isActive ? sprintf('Made the %s available to allocate.', self::noun($item->itemType)) : sprintf('Stopped the %s being allocated.', self::noun($item->itemType)))
                        : sprintf('Changed %s.', implode(', ', $changed)),
                    ['itemType' => $item->itemType, 'changedFields' => $changed, 'isActive' => $isActive],
                    self::PERMISSION,
                ),
            ], $actor);
        }

        return $this->detail($item->itemType, $item->publicId);
    }

    /**
     * Starts the next version as a copy of the live one — or of the newest
     * published one, if the item has been retired from allocation.
     */
    public function createDraft(ActorContext $actor, string $itemType, string $itemPublicId): ContentVersionRecord
    {
        $item = $this->requireItem($itemType, $itemPublicId);
        $from = $this->content->latestPublishedVersion($item);
        $versionPublicId = $this->ids->generate()->toString();
        $content = $from === null ? AuthoredContent::empty($item->itemType) : $from->content;

        try {
            $this->content->createDraft($item, $versionPublicId, $content, [
                OnboardingAdminEvent::succeeded(
                    AdminEventType::CONTENT_DRAFT_CREATED,
                    null,
                    $versionPublicId,
                    $from === null ? 'Started an empty draft.' : sprintf('Started a draft from version %d.', $from->versionNumber),
                    ['itemType' => $item->itemType, 'itemPublicId' => $item->publicId, 'copiedFromVersionNumber' => $from?->versionNumber],
                    self::PERMISSION,
                ),
            ], $actor);
        } catch (ContentAuthoringNotPermitted $refused) {
            $this->refuse($actor, $item, $refused, ['itemType' => $item->itemType, 'itemPublicId' => $item->publicId]);
        }

        return $this->requireVersion($item, $versionPublicId);
    }

    /**
     * @param array<string, mixed> $body the whole body for the item type, each time
     */
    public function saveDraft(
        ActorContext $actor,
        string $itemType,
        string $itemPublicId,
        string $versionPublicId,
        RowVersionToken $expectedRowVersion,
        array $body,
    ): ContentVersionRecord {
        $item = $this->requireItem($itemType, $itemPublicId);
        $version = $this->requireVersion($item, $versionPublicId);
        $details = ['itemType' => $item->itemType, 'itemPublicId' => $item->publicId, 'versionNumber' => $version->versionNumber];

        if (!$version->isDraft()) {
            $this->refuse($actor, $item, ContentAuthoringNotPermitted::versionPublished(), $details);
        }

        $content = AuthoredContent::fromInput($item->itemType, $body, $item->accessGroup);
        if ($content->sameAs($version->content)) {
            return $version;
        }

        $this->content->saveDraft($item, $version, $expectedRowVersion, $content, [
            OnboardingAdminEvent::succeeded(
                AdminEventType::CONTENT_DRAFT_SAVED,
                null,
                $version->publicId,
                sprintf('Saved the draft of version %d.', $version->versionNumber),
                $details + $content->counts(),
                self::PERMISSION,
            ),
        ], $actor);

        return $this->requireVersion($item, $version->publicId);
    }

    /**
     * @return array{version: ContentVersionRecord, retiredVersionNumber: ?int, reissuedCount: int}
     */
    public function publish(
        ActorContext $actor,
        string $itemType,
        string $itemPublicId,
        string $versionPublicId,
        RowVersionToken $expectedRowVersion,
        bool $supersedesWorkInProgress,
    ): array {
        $item = $this->requireItem($itemType, $itemPublicId);
        $version = $this->requireVersion($item, $versionPublicId);
        $details = ['itemType' => $item->itemType, 'itemPublicId' => $item->publicId, 'versionNumber' => $version->versionNumber];

        if (!$version->isDraft()) {
            $this->refuse($actor, $item, ContentAuthoringNotPermitted::versionPublished(), $details);
        }
        $problems = $version->content->publishProblems($item->accessGroup);
        if ($problems !== []) {
            $this->refuse($actor, $item, ContentAuthoringNotPermitted::notPublishable($problems), $details);
        }

        $noun = self::noun($item->itemType);
        $result = $this->content->publish(
            $item,
            $version,
            $expectedRowVersion,
            $supersedesWorkInProgress,
            static function (array $reissued, ?int $retiredVersionNumber) use ($version, $item, $details, $supersedesWorkInProgress, $noun): array {
                /** @var list<ContentReissue> $reissued */
                $events = [
                    OnboardingAdminEvent::succeeded(
                        AdminEventType::CONTENT_PUBLISHED,
                        null,
                        $version->publicId,
                        $retiredVersionNumber === null
                            ? sprintf('Published version %d of the %s.', $version->versionNumber, $noun)
                            : sprintf('Published version %d of the %s, replacing version %d.', $version->versionNumber, $noun, $retiredVersionNumber),
                        $details + $version->content->counts() + [
                            'retiredVersionNumber' => $retiredVersionNumber,
                            'supersedesWorkInProgress' => $supersedesWorkInProgress,
                            'reissuedCount' => count($reissued),
                        ],
                        self::PERMISSION,
                    ),
                ];
                foreach ($reissued as $move) {
                    $events[] = OnboardingAdminEvent::succeeded(
                        AdminEventType::ALLOCATION_REISSUED,
                        $move->userId,
                        $move->toAssignmentPublicId,
                        sprintf('Moved onto version %d, which supersedes work in progress.', $version->versionNumber),
                        [
                            'itemType' => $item->itemType,
                            'itemPublicId' => $item->publicId,
                            'versionPublicId' => $version->publicId,
                            'versionNumber' => $version->versionNumber,
                            'fromAssignmentPublicId' => $move->fromAssignmentPublicId,
                            'fromStatus' => $move->fromStatus,
                            'toStatus' => SupersessionPolicy::statusAfterReissue()->toString(),
                        ],
                        self::PERMISSION,
                    );
                }

                return $events;
            },
            $actor,
        );

        return [
            'version' => $this->requireVersion($item, $version->publicId),
            'retiredVersionNumber' => $result['retiredVersionNumber'],
            'reissuedCount' => count($result['reissued']),
        ];
    }

    /**
     * Deletes an item nobody has ever been given — a mistake, a test, an import
     * that should not have happened. Once anybody holds a version, even one
     * since withdrawn, it is refused: their allocation points at it, and so may
     * their work. "Offered for allocation" is how such an item is put away.
     *
     * The audit row keeps the slug and the version count, never the wording.
     */
    public function deleteItem(ActorContext $actor, string $itemType, string $itemPublicId, RowVersionToken $expectedRowVersion): void
    {
        $item = $this->requireItem($itemType, $itemPublicId);
        $details = ['itemType' => $item->itemType, 'itemPublicId' => $item->publicId, 'slug' => $item->slug];

        $allocations = $this->content->allocationCount($item);
        if ($allocations > 0) {
            $this->refuse($actor, $item, ContentAuthoringNotPermitted::inUse($allocations), $details + ['allocationCount' => $allocations]);
        }

        try {
            $this->content->deleteItem($item, $expectedRowVersion, [
                OnboardingAdminEvent::succeeded(
                    AdminEventType::CONTENT_ITEM_DELETED,
                    null,
                    $item->publicId,
                    sprintf('Deleted the %s "%s", which nobody had been given.', self::noun($item->itemType), $item->slug),
                    $details + ['versionCount' => count($this->content->listVersions($item))],
                    self::PERMISSION,
                ),
            ], $actor);
        } catch (ContentAuthoringNotPermitted $refused) {
            $this->refuse($actor, $item, $refused, $details);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function detail(string $itemType, string $itemPublicId): ContentItemDetail
    {
        $item = $this->requireItem($itemType, $itemPublicId);

        return new ContentItemDetail($item, $this->content->listVersions($item), $this->content->allocationCount($item));
    }

    private function requireItem(string $itemType, string $itemPublicId): ContentItemRecord
    {
        if (!in_array($itemType, AuthoredContent::TYPES, true)) {
            throw OnboardingContentNotFound::item($itemPublicId);
        }

        return $this->content->findItem($itemType, strtolower(trim($itemPublicId)))
            ?? throw OnboardingContentNotFound::item($itemPublicId);
    }

    private function requireVersion(ContentItemRecord $item, string $versionPublicId): ContentVersionRecord
    {
        return $this->content->findVersion($item, strtolower(trim($versionPublicId)))
            ?? throw OnboardingContentNotFound::version($versionPublicId);
    }

    /**
     * @param array<string, scalar|null|list<scalar>> $details
     *
     * @throws ContentAuthoringNotPermitted always
     */
    private function refuse(ActorContext $actor, ?ContentItemRecord $item, ContentAuthoringNotPermitted $refusal, array $details): never
    {
        $this->audit->append($actor, OnboardingAdminEvent::refused(
            AdminEventType::CONTENT_REFUSED,
            null,
            $item?->publicId,
            $refusal->getMessage(),
            $details + ['reason' => $refusal->reason],
            self::PERMISSION,
        ));

        throw $refusal;
    }

    /**
     * @param array<string, bool> $comparisons field => whether it changed
     *
     * @return list<string>
     */
    private static function changed(array $comparisons): array
    {
        $changed = [];
        foreach ($comparisons as $field => $isChanged) {
            if ($isChanged) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private static function type(string $itemType): string
    {
        if (!in_array($itemType, AuthoredContent::TYPES, true)) {
            throw InvalidOnboardingValue::forOneOf('item type', $itemType, AuthoredContent::TYPES);
        }

        return $itemType;
    }

    private static function noun(string $itemType): string
    {
        return match ($itemType) {
            AuthoredContent::TYPE_TRAINING => 'training module',
            AuthoredContent::TYPE_POLICY => 'policy',
            default => 'form',
        };
    }

    private static function slug(mixed $value): string
    {
        $slug = is_string($value) ? strtolower(trim($value)) : '';
        if ($slug === '' || strlen($slug) > self::MAX_SLUG_LENGTH || preg_match(self::SLUG, $slug) !== 1) {
            throw InvalidOnboardingValue::for('short name', ($slug === '' ? '(missing)' : $slug) . ' — lower-case words joined by hyphens');
        }

        return $slug;
    }

    private static function title(mixed $value): string
    {
        $title = is_string($value) ? trim($value) : '';
        if ($title === '') {
            throw InvalidOnboardingValue::required('title', 'every item needs one.');
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw InvalidOnboardingValue::for('title', sprintf('longer than %d characters', self::MAX_TITLE_LENGTH));
        }

        return $title;
    }

    private static function summary(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for('summary', '(not text)');
        }
        $summary = trim($value);
        if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            throw InvalidOnboardingValue::for('summary', sprintf('longer than %d characters', self::MAX_SUMMARY_LENGTH));
        }

        return $summary === '' ? null : $summary;
    }

    private static function chapterTitle(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for('chapter', '(not text)');
        }
        $chapter = trim($value);
        if (mb_strlen($chapter) > self::MAX_CHAPTER_LENGTH) {
            throw InvalidOnboardingValue::for('chapter', sprintf('longer than %d characters', self::MAX_CHAPTER_LENGTH));
        }

        return $chapter === '' ? null : $chapter;
    }

    private static function sortOrder(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) || $value < 1 || $value > self::MAX_SORT_ORDER) {
            throw InvalidOnboardingValue::for('position', sprintf('%s — a whole number from 1 to %d', is_scalar($value) ? (string) $value : '(not a number)', self::MAX_SORT_ORDER));
        }

        return $value;
    }

    private static function accessGroup(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ACCESS_GROUPS, true)) {
            throw InvalidOnboardingValue::forOneOf('access group', is_string($value) ? $value : '(missing)', self::ACCESS_GROUPS);
        }

        return $value;
    }
}
