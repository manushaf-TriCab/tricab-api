<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemSummary;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentReissue;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionSummary;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * HR's authoring of training modules, policies and forms (4.4).
 *
 * ## A published version is frozen, and this is where that is enforced
 *
 * Every write to a version's body is conditional on the version still being a
 * draft, in the same statement that checks its token. The schema does not hold
 * this line with a trigger (see the plan's 4.4 evidence for why), so an
 * implementation that updated a version by id alone would quietly rewrite the
 * wording somebody acknowledged. `AzureSqlOnboardingContentRepositoryTest`
 * checks a published version refuses a save.
 *
 * Every write appends its audit events in the same transaction.
 */
interface OnboardingContentRepository
{
    /**
     * Every item of every type, ordered by type, then title, then public id.
     *
     * @return list<ContentItemSummary>
     */
    public function listItems(): array;

    public function findItem(string $itemType, string $itemPublicId): ?ContentItemRecord;

    /**
     * Newest first.
     *
     * @return list<ContentVersionSummary>
     */
    public function listVersions(ContentItemRecord $item): array;

    public function findVersion(ContentItemRecord $item, string $versionPublicId): ?ContentVersionRecord;

    /** The live version, or failing that the newest published one — what a new draft starts from. */
    public function latestPublishedVersion(ContentItemRecord $item): ?ContentVersionRecord;

    public function slugTaken(string $itemType, string $slug): bool;

    /**
     * Every allocation ever made of any version, withdrawn ones included: a
     * withdrawn allocation still points at its version, and so may the work
     * done under it.
     */
    public function allocationCount(ContentItemRecord $item): int;

    /**
     * Deletes the item, its versions and what hangs off them (questions, field
     * classifications) and appends `$events`, in one unit — but only while
     * nobody has been given it. The check is repeated under lock inside the
     * unit, and the foreign keys from `Assignments` are the last word.
     *
     * @param list<OnboardingAdminEvent> $events
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted in_use, when an allocation landed first
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict on a stale token
     */
    public function deleteItem(ContentItemRecord $item, RowVersionToken $expectedRowVersion, array $events, ActorContext $actor): void;

    /**
     * Creates the item and its first, empty draft together.
     *
     * @param list<OnboardingAdminEvent> $events
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted slug_taken, when another author took it first
     */
    public function createItem(
        string $itemType,
        string $itemPublicId,
        string $slug,
        string $title,
        ?string $summary,
        ?string $accessGroup,
        ?string $chapterTitle,
        ?int $sortOrder,
        string $versionPublicId,
        AuthoredContent $initial,
        array $events,
        ActorContext $actor,
    ): void;

    /**
     * @param list<OnboardingAdminEvent> $events
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict on a stale token
     */
    public function updateItem(
        ContentItemRecord $item,
        RowVersionToken $expectedRowVersion,
        string $title,
        ?string $summary,
        ?string $chapterTitle,
        ?int $sortOrder,
        bool $isActive,
        array $events,
        ActorContext $actor,
    ): void;

    /**
     * @param list<OnboardingAdminEvent> $events
     *
     * @return int the new version's number
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted draft_exists
     */
    public function createDraft(
        ContentItemRecord $item,
        string $versionPublicId,
        AuthoredContent $content,
        array $events,
        ActorContext $actor,
    ): int;

    /**
     * Replaces the draft's body. Refused once published.
     *
     * @param list<OnboardingAdminEvent> $events
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted version_published
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict on a stale token
     */
    public function saveDraft(
        ContentItemRecord $item,
        ContentVersionRecord $version,
        RowVersionToken $expectedRowVersion,
        AuthoredContent $content,
        array $events,
        ActorContext $actor,
    ): void;

    /**
     * Publishes the draft and retires the version it replaces, in one
     * transaction.
     *
     * When `$supersedesWorkInProgress` is set, every unfinished, unwithdrawn
     * assignment of the replaced version held by somebody who has not left is
     * withdrawn and a fresh `not_started` one made on the new version, keeping its
     * due date. Completed assignments are never touched
     * ({@see \TriCab\Core\Onboarding\Domain\Service\SupersessionPolicy}).
     *
     * `$eventsFor` is called inside the transaction with the people moved, so
     * each move is audited in the same unit as the move itself.
     *
     * @param callable(list<ContentReissue>, ?int): list<OnboardingAdminEvent> $eventsFor given the moves and the retired version's number
     *
     * @return array{retiredVersionNumber: ?int, reissued: list<ContentReissue>}
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted version_published
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict on a stale token
     */
    public function publish(
        ContentItemRecord $item,
        ContentVersionRecord $version,
        RowVersionToken $expectedRowVersion,
        bool $supersedesWorkInProgress,
        callable $eventsFor,
        ActorContext $actor,
    ): array;
}
